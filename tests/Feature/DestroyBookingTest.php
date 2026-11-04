<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DestroyBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_cancels_own_booking_and_gets_204(): void
    {
        $author = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();

        Sanctum::actingAs($author);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertNoContent();

        $this->assertModelMissing($booking);
    }

    public function test_does_not_delete_booking_of_another_user_and_returns_404(): void
    {
        $booking = Booking::factory()->create();

        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Бронь не найдена или не принадлежит вам');

        $this->assertModelExists($booking);
    }

    public function test_returns_404_for_unknown_booking(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/9999')->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson("/api/bookings/{$booking->id}")->assertUnauthorized();

        $this->assertModelExists($booking);
    }
}
