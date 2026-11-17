<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
         return Booking::where('user_id', $request->user()->id)
            ->with('room')
            ->orderByRaw('starts_at DESC')
            ->paginate(20)
            ->through(fn ($b) => [
                'id' => $b->id,
                'title' => $b->title,
                'room' => $b->room->name,
                'starts_at' => $b->starts_at,
                'ends_at' => $b->ends_at,
            ]);
    }

    public function store(Request $request)
    {
        // Валидация входных данных запроса
        $validated = $request->validate([
            'room_id' => 'required|integer|exists:rooms,id',
            'title' => 'required|string|max:255',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date',
        ]);

        $room = Room::with('office')->findOrFail($validated['room_id']);
        $timezone = $room->office->timezone; // Europe/Moscow или Asia/Novosibirsk

        // Парсим и переводим в таймзону офиса
        $start = Carbon::parse($validated['starts_at'], $timezone)->setTimezone($timezone)->startOfMinute();
        $end   = Carbon::parse($validated['ends_at'], $timezone)->setTimezone($timezone)->startOfMinute();

        // Длительность
        $duration = (int) $start->diffInMinutes($end, false);
        if ($duration === 0) {
            $this->err('ends_at', 'Минимальная длительность брони — 15 минут');
        }

        // Относительность меток времени (end < start)
        if ($duration < 0) {
            $this->err('ends_at', 'Время окончания брони не может быть меньше времени начала');
        }

        // Проверка шага 15 минут
        if ($start->minute % 15 !== 0 || $end->minute % 15 !== 0) {
            $this->err('starts_at', 'Время должно быть кратно 15 минутам');
        }

        // Проверка длительности (15 мин – 4 часа)
        if ($duration < 15 || $duration > 240) {
            $this->err('ends_at', 'Длительность должна быть от 15 минут до 4 часов');
        }

        // Проверка рабочего времени (09:00–20:00 по местному времени)
        if ($start->hour < 9 || $end->hour > 20 || ($end->hour === 20 && $end->minute > 0)) {
            $this->err('starts_at', 'Бронь должна укладываться в рабочее время 09:00–20:00.');
        }

        // Бронировать можно не более чем за N дней
        /*$n = 30;
        if ($start->greaterThan(Carbon::now($timezone)->addDays($n))) {
            $this->err('starts_at', 'Бронировать можно не более чем за '.$n.' дней');
        }*/

        // Бронировать можно только в будние дни
        /*if ($start->isWeekend()) {
            $this->err('starts_at', 'Бронировать можно только в будние дни');
        }*/

        // Проверка пересечений (в UTC, чтобы не зависеть от таймзоны)
        $startUtc = $start->copy()->setTimezone('UTC');
        $endUtc = $end->copy()->setTimezone('UTC');

        // Пишем в БД
        $booking = DB::transaction(function () use ($request, $validated, $room, $startUtc, $endUtc) {
            // Блокируем строку комнаты — второй запрос будет ждать здесь
            Room::where('id', $room->id)->lockForUpdate()->first();

            // Теперь проверка конфликта и вставка — в одной транзакции
            $conflict = Booking::where('room_id', $room->id)
                //->where('user_id', '!=', $request->user()->id) - разрешить пересечение своих броней
                ->where('starts_at', '<', $endUtc)
                ->where('ends_at', '>', $startUtc)
                ->exists();

            if ($conflict) {
                $this->err('starts_at', 'Комната занята в это время');
            }

            return Booking::create([
                'room_id' => $validated['room_id'],
                'user_id' => $request->user()->id,
                'title' => $validated['title'],
                'starts_at' => $startUtc,
                'ends_at' => $endUtc,
            ]);
        });

        Log::withContext(['booking_id' => $booking->id]);
        Log::channel('bookings')->info('Booking created');

        return response()->json($booking, 201);
    }

    public function destroy(Request $request, $id)
    {
        $booking = $request->user()->bookings()->find($id);

        if (! $booking) {
            return response()->json(['message' => 'Бронь не найдена или не принадлежит вам'], 404);
        }

        $booking->delete();

        return response()->noContent();
    }

    /*public function today(Room $room)
    {
        return $room->bookings()
            ->with('user')
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at . ' – ' . $b->ends_at,
                'author' => $b->user->name,
            ]);
    }*/

    // today с исправленной логикой учета таймзоны клиента ()
    public function today(Room $room)
    {
        $timezone = $room->office->timezone;
        
        // Границы «сегодня» в таймзоне офиса, переведённые в UTC
        $startOfDay = Carbon::now($timezone)->startOfDay()->setTimezone('UTC');
        $endOfDay   = Carbon::now($timezone)->endOfDay()->setTimezone('UTC');
        
        return $room->bookings()
            ->with('user')
            ->whereBetween('starts_at', [$startOfDay, $endOfDay])
            ->get()
            ->map(fn ($b) => [
                'timeUTC' => $b->starts_at . ' – ' . $b->ends_at,
                'time' => $this->setRoomTime($room, $b->starts_at) . ' - ' . $this->setRoomTime($room, $b->ends_at),
                'author' => $b->user->name,
            ]);
    }

    /**
     * officeBookings - получение всех существующих броней по офису на дату - GET: <url>/offices/{office}/bookings?date={date}
     *
     * @param Office $office
     * @param Request $request
     * @return json {room_id, room_name, author, time, booking_id}
     */
    public function officeBookings(Office $office, Request $request) {
        $validated = $request->validate([
            'date' => 'nullable|date',
        ]);

        $timezone = $office->timezone;

        // Дата - переданная или текущая
        $date = $validated['date'] ?? null;
        if(!$date) {
            $date = Carbon::now($timezone)->toDateTimeString();
        }

        // Дата-день в таймзоне офиса
        $startOfDay = Carbon::parse($date, $timezone)->startOfDay()->setTimezone('UTC');
        $endOfDay   = Carbon::parse($date, $timezone)->endOfDay()->setTimezone('UTC');

        // Все переговорные по офису
        $rooms = Room::where('office_id', $office->id)->get();
        if($rooms->isEmpty()) {
            return $this->err('office_id', 'В этом офисе нет переговорных');
        }
        $ids = $rooms->pluck('id');

        // Брони для всех переговорных по офису
        $bookings = Booking::whereIn('room_id', $ids)
            ->whereBetween('starts_at', [$startOfDay, $endOfDay])
            ->with('user', 'room')
            ->get()
            ->map(fn ($b) => [
                'room_id' => $b->room_id,
                'room_name' => $b->room->name,
                'author' => $b->user->name,
                'time' => $this->setRoomTime($b->room, $b->starts_at) . ' - ' . $this->setRoomTime($b->room, $b->ends_at),
                'booking_id' => $b->id,
            ]);

        return response()->json($bookings, 200);
    }

    // Перевод даты $date в таймзону офиса-переговорки
    private function setRoomTime(Room $room, \Carbon\Carbon $date) 
    {
        $timezone = $room->office->timezone;
        return $date->copy()->setTimezone($timezone)->toDateTimeString();
    }

    private function err(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => $message,
        ]);

        // abort(422, $message);
        // response()->json(['message' => ''], 404);
    }
}
