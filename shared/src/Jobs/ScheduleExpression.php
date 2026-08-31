<?php

namespace Z77\Shared\Jobs;

/**
 * When a schedule is next due (ADR-031). Four deliberately small forms:
 *
 *   every:15m            every 15 minutes, measured from the last run
 *   every:2h             every 2 hours, likewise
 *   hourly@:20           every hour at minute 20
 *   daily@03:15          every day at 03:15
 *   weekly@mon,03:15     every Monday at 03:15
 *
 * NOT a cron expression, on purpose. A cron parser is a few hundred lines with
 * its own edge cases, and its errors are silent — `15 3 * * 7` looks right and
 * fires on the wrong day. These four forms cover what a website installation
 * actually schedules, read out loud correctly, and map onto a backend select
 * box. A cron form can be added later as a fifth case without changing anything
 * around it.
 *
 * The wall-clock forms ignore the last run: 03:15 means 03:15, whether or not
 * yesterday's pass happened. `every:` is the only form that measures from the
 * last run — that is what "every 15 minutes" means.
 */
final class ScheduleExpression
{
    private const DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    private function __construct(
        private string $raw,
        private string $kind,
        private int $intervalSeconds,
        private int $hour,
        private int $minute,
        private int $weekday,
    ) {}

    /** @throws \InvalidArgumentException on an unreadable expression */
    public static function parse(string $expression): self
    {
        $expression = strtolower(trim($expression));

        if (preg_match('/^every:(\d+)(m|h)$/', $expression, $m)) {
            $count = (int) $m[1];
            if ($count < 1) {
                throw new \InvalidArgumentException("Schedule '{$expression}': interval must be at least 1");
            }
            return new self($expression, 'every', $count * ($m[2] === 'h' ? 3600 : 60), 0, 0, 0);
        }

        if (preg_match('/^hourly@:(\d{1,2})$/', $expression, $m)) {
            return new self($expression, 'hourly', 0, 0, self::minute($expression, (int) $m[1]), 0);
        }

        if (preg_match('/^daily@(\d{1,2}):(\d{2})$/', $expression, $m)) {
            return new self($expression, 'daily', 0, self::hour($expression, (int) $m[1]), self::minute($expression, (int) $m[2]), 0);
        }

        if (preg_match('/^weekly@([a-z]{3}),(\d{1,2}):(\d{2})$/', $expression, $m)) {
            if (!isset(self::DAYS[$m[1]])) {
                throw new \InvalidArgumentException("Schedule '{$expression}': unknown weekday '{$m[1]}' (mon…sun)");
            }
            return new self($expression, 'weekly', 0, self::hour($expression, (int) $m[2]), self::minute($expression, (int) $m[3]), self::DAYS[$m[1]]);
        }

        throw new \InvalidArgumentException(
            "Schedule '{$expression}' is not readable. Use every:15m, every:2h, hourly@:20, daily@03:15 or weekly@mon,03:15."
        );
    }

    /** True when the expression is valid — for validating operator input without catching. */
    public static function isValid(string $expression): bool
    {
        try {
            self::parse($expression);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * The next moment this schedule is due, strictly after $after.
     *
     * @param int|null $lastRun only consulted by the `every:` form; null means
     *                          "never ran", which makes it due immediately
     */
    public function nextAfter(int $after, ?int $lastRun = null): int
    {
        if ($this->kind === 'every') {
            return $lastRun === null ? $after : $lastRun + $this->intervalSeconds;
        }

        $from = (new \DateTimeImmutable('@' . $after))->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        $candidate = match ($this->kind) {
            'hourly' => $from->setTime((int) $from->format('G'), $this->minute, 0),
            default  => $from->setTime($this->hour, $this->minute, 0),
        };

        if ($this->kind === 'weekly') {
            $shift     = ($this->weekday - (int) $candidate->format('N') + 7) % 7;
            $candidate = $candidate->modify("+{$shift} day");
        }

        if ($candidate->getTimestamp() > $after) {
            return $candidate->getTimestamp();
        }

        return match ($this->kind) {
            'hourly' => $candidate->modify('+1 hour')->getTimestamp(),
            'daily'  => $candidate->modify('+1 day')->getTimestamp(),
            default  => $candidate->modify('+7 day')->getTimestamp(),
        };
    }

    public function __toString(): string
    {
        return $this->raw;
    }

    private static function hour(string $expression, int $hour): int
    {
        if ($hour > 23) {
            throw new \InvalidArgumentException("Schedule '{$expression}': hour {$hour} is out of range (0–23)");
        }
        return $hour;
    }

    private static function minute(string $expression, int $minute): int
    {
        if ($minute > 59) {
            throw new \InvalidArgumentException("Schedule '{$expression}': minute {$minute} is out of range (0–59)");
        }
        return $minute;
    }
}
