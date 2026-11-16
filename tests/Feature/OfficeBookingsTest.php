<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfficeBookingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_office_bookings(): void
    {
        $user = User::factory()->create();

        $office1 = Office::factory()->create(['timezone' => 'Europe/Moscow']);
        $office2 = Office::factory()->create(['timezone' => 'Asia/Novosibirsk']);

        $room1 = Room::factory()->for($office1)->create();
        $room2 = Room::factory()->for($office2)->create();

       $booking1 = Booking::factory()
            ->for($room1)
            ->for($user)
            ->create([
                'starts_at' => '2026-11-16 10:00:00',
                'ends_at' => '2026-11-16 11:00:00',
            ]);

        $booking2 = Booking::factory()
            ->for($room2)
            ->for($user)
            ->create([
                'starts_at' => '2026-11-16 10:00:00',
                'ends_at' => '2026-11-16 11:00:00',
            ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/offices/{$office1->id}/bookings")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.room_id', $room1->id)
            ->assertJsonMissing(['room_id' => $room2->id]);
        
    }

    public function test_time_is_displayed_in_office_timezone(): void
    {
        $user = User::factory()->create();

        // Офис в Новосибирске (+7)
        $office = Office::factory()->create(['timezone' => 'Asia/Novosibirsk']);
        $room = Room::factory()->for($office)->create();

        // Бронь: 10:00–11:00 по Новосибирску = 03:00–04:00 UTC
        Booking::factory()
            ->for($room)
            ->for($user)
            ->create([
                'starts_at' => '2026-11-16 03:00:00', // UTC
                'ends_at'   => '2026-11-16 04:00:00', // UTC
            ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/offices/{$office->id}/bookings?date=2026-11-16");

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.time', '2026-11-16 10:00:00 - 2026-11-16 11:00:00');
    }

    public function test_requires_authentication(): void
    {
        $office = Office::factory()->create();

        $this->getJson("/api/offices/{$office->id}/bookings")->assertUnauthorized();
    }
}
