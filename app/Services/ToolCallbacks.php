<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ToolCallbacks
{
    public static function weather(string $location): string
    {
        return "Weather is sunny in $location";
    }

    public static function dateTimeNow(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Fetch all available time slots that can fit an appointment
     * of the given duration, ordered earliest to latest.
     */
    public static function getAvailableSlots(?string $date, int $duration_minutes): string
    {
        // Input validation
        if ($duration_minutes <= 0) {
            return 'Error: Duration must be positive';
        }
        if ($date === null) {
            $date = date('Y-m-d');
        } else if (($ts = strtotime($date)) === false) {
            return 'Error: Invalid date format';
        }

        $tz = new \DateTimeZone('UTC'); // or application timezone
        $dayStart = (new \DateTime("$date 09:00:00", $tz))->getTimestamp();
        $dayEnd   = (new \DateTime("$date 17:00:00", $tz))->getTimestamp();

        $existing = DB::table('appointments')
            ->where('start_time', '<', $dayEnd)
            ->orderBy('start_time')
            ->get(['start_time', 'duration_minutes']);

        // Build busy intervals and clamp to working window
        $busy = [];
        foreach ($existing as $appt) {
            $start = $appt->start_time;
            $end   = $start + $appt->duration_minutes * 60;
            if ($end <= $dayStart || $start >= $dayEnd) {
                continue;
            }
            $busy[] = [max($start, $dayStart), min($end, $dayEnd)];
        }

        // Merge (already sorted by DB)
        $merged = [];
        foreach ($busy as $b) {
            if (empty($merged) || $b[0] > $merged[count($merged) - 1][1]) {
                $merged[] = $b;
            } else {
                $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $b[1]);
            }
        }

        // Compute free gaps
        $free = [];
        $cursor = $dayStart;
        foreach ($merged as $interval) {
            if ($interval[0] - $cursor >= $duration_minutes * 60) {
                $free[] = [
                    'start_time' => date('Y-m-d\TH:i:s', $cursor),
                    'end_time'   => date('Y-m-d\TH:i:s', $interval[0]),
                ];
            }
            $cursor = $interval[1];
        }
        if ($dayEnd - $cursor >= $duration_minutes * 60) {
            $free[] = [
                'start_time' => date('Y-m-d\TH:i:s', $cursor),
                'end_time'   => date('Y-m-d\TH:i:s', $dayEnd),
            ];
        }

        // Return data, not JSON
        if (empty($free)) {
            return "No available slots of {$duration_minutes} minutes on {$date}.";
        }

        return json_encode($free) ?? 'Error: JSON encoding the response failed.';
    }

    /**
     * Book an appointment into a confirmed time slot.
     */
    public static function bookAppointment(
        string $start_time,
        int $duration_minutes,
        string $title,
        string $customer_name
    ): string {
        $start_timestamp = strtotime($start_time);

        $id = DB::table('appointments')->insertGetId([
            'start_time'       => $start_timestamp,
            'duration_minutes' => $duration_minutes,
            'title'            => $title,
            'customer_name'    => $customer_name,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return json_encode([
            'success'     => true,
            'message'     => 'Appointment booked successfully.',
            'appointment' => [
                'id'           => $id,
                'start_time'   => $start_timestamp,
                'title'        => $title,
                'customer'     => $customer_name,
            ],
        ]) ?? 'Error: JSON encoding the response failed.';
    }
}
