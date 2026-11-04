<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
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
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(Request $request)
    {
        // Валидация входных данных запроса
        $validated = $request->validate([
            'room_id' => 'required|integer|exists:rooms,id',
            'title' => 'required|string|max:255',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
        ]);

        $room = Room::with('office')->findOrFail($validated['room_id']);
        $timezone = $room->office->timezone; // Europe/Moscow или Asia/Novosibirsk

        // Парсим и переводим в таймзону офиса
        $start = Carbon::parse($validated['starts_at'], $timezone)->startOfMinute();
        $end   = Carbon::parse($validated['ends_at'], $timezone)->startOfMinute();

        // Проверка шага 15 минут
        if ($start->minute % 15 !== 0 || $end->minute % 15 !== 0) {
            throw ValidationException::withMessages([
                'starts_at' => 'Время должно быть кратно 15 минутам.',
            ]);
        }

        // Проверка длительности (15 мин – 4 часа)
        $duration = $start->diffInMinutes($end);
        if ($duration < 15 || $duration > 240) {
            throw ValidationException::withMessages([
                'ends_at' => 'Длительность должна быть от 15 минут до 4 часов.',
            ]);
        }

        // Проверка рабочего времени (09:00–20:00 по местному времени)
        if ($start->hour < 9 || $end->hour > 20 || ($end->hour === 20 && $end->minute > 0)) {
            throw ValidationException::withMessages([
                'starts_at' => 'Бронь должна укладываться в рабочее время 09:00–20:00.',
            ]);
        }

        // Проверка пересечений (в UTC, чтобы не зависеть от таймзоны)
        $startUtc = $start->copy()->setTimezone('UTC');
        $endUtc = $end->copy()->setTimezone('UTC');

        $conflict = Booking::where('room_id', $room->id)
            ->where('starts_at', '<', $endUtc) // начало существующей < конец новой
            ->where('ends_at', '>', $startUtc)   // конец существующей > начало новой
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'starts_at' => 'Комната занята в это время.',
            ]);
        }

        // Создание 
        $booking = Booking::create([
            'room_id' => $validated['room_id'],
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'starts_at' => $startUtc,
            'ends_at' => $endUtc,
        ]);

        Log::withContext(['booking_id' => $booking->id]);
        Log::channel('bookings')->info('Booking created');

        return response()->json($booking, 201);
    }

    public function destroy(Request $request, $id)
    {
        
        // $id типизирован на уровне routes/api.php
        $booking = $request->user()->bookings()->findOrFail($id);
        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->with('user')
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at . ' – ' . $b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
