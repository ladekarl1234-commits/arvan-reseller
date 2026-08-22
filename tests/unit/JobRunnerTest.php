<?php
/**
 * The durable queue (EX-074): `grep -rn "JobRunner" tests/` used to return
 * nothing at all, so backoff indexing, the dead-letter cap and — the state
 * with no exit — the stale-claim reaper were entirely unexercised.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Jobs\JobRunner;
use ArvanReseller\Support\Helpers;

final class JobRunnerTest extends Arvrs_DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__arvrs_job_calls'] = [];
        // The registry is a static, so it is re-seeded per test rather than
        // leaking whichever handler the first test happened to register.
        JobRunner::handle('test_ok', $this->record_handler());
        JobRunner::handle('always_fails', $this->failing_handler());
    }

    private function record_handler(): callable
    {
        return static function (array $payload): void {
            $GLOBALS['__arvrs_job_calls'][] = $payload;
        };
    }

    private function failing_handler(): callable
    {
        return static function (array $payload): void {
            throw new \RuntimeException('upstream is down');
        };
    }

    private function job(int $id): array
    {
        return (array) JobRunner::detail($id);
    }

    public function test_a_job_runs_through_the_registry_and_finishes(): void
    {
        $job_id = JobRunner::enqueue('test_ok', ['n' => 7]);

        $this->assertSame(1, JobRunner::run_due());
        $this->assertSame([['n' => 7]], $GLOBALS['__arvrs_job_calls']);
        $this->assertSame('done', $this->job($job_id)['status']);
        $this->assertSame(1, (int) $this->job($job_id)['attempts']);
    }

    /** The registry is the extension point; an unknown type fails loudly. */
    public function test_an_unregistered_type_is_an_error_not_a_silent_success(): void
    {
        $job_id = JobRunner::enqueue('nobody_handles_this', []);
        JobRunner::run_due();
        $this->assertStringContainsString('Unknown job type', (string) $this->job($job_id)['last_error']);
        $this->assertSame('pending', $this->job($job_id)['status'], 'retryable until the attempt cap');
    }

    public function test_handlers_are_filterable_so_a_companion_plugin_can_add_a_type(): void
    {
        add_filter('arvrs_job_handlers', static function (array $map): array {
            $map['from_a_filter'] = static function (array $payload): void {
                $GLOBALS['__arvrs_job_calls'][] = 'filtered';
            };
            return $map;
        });
        JobRunner::enqueue('from_a_filter', []);
        JobRunner::run_due();
        $this->assertSame(['filtered'], $GLOBALS['__arvrs_job_calls']);
    }

    /**
     * Backoff and dead-lettering. `BACKOFF = [1, 2, 5, 15, 30]` minutes is
     * indexed by attempt number, and the last attempt must dead-letter rather
     * than schedule a sixth.
     */
    public function test_failures_back_off_on_a_ladder_and_dead_letter_at_the_cap(): void
    {
        $job_id = JobRunner::enqueue('always_fails', []);

        $expected_minutes = [1, 2, 5, 15, 30];
        foreach ($expected_minutes as $attempt => $minutes) {
            // The runner only picks up jobs whose run_at has arrived; the
            // backoff it just wrote is what we are measuring, so rewind it.
            $this->db->query('UPDATE ' . JobRunner::table() . " SET run_at = '2000-01-01 00:00:00' WHERE id = " . $job_id);
            $before = time();
            JobRunner::run_due();
            $row = $this->job($job_id);

            $this->assertSame($attempt + 1, (int) $row['attempts']);
            $this->assertStringContainsString('upstream is down', (string) $row['last_error']);
            if ($attempt < 4) {
                $this->assertSame('pending', $row['status']);
                $delay = strtotime($row['run_at'] . ' UTC') - $before;
                $this->assertGreaterThanOrEqual($minutes * 60 - 2, $delay, 'attempt ' . ($attempt + 1) . ' backoff');
                $this->assertLessThanOrEqual($minutes * 60 + 5, $delay);
            }
        }

        $this->assertSame('dead', $this->job($job_id)['status'], 'attempts >= max_attempts must dead-letter');
        $this->assertSame(5, (int) $this->job($job_id)['attempts']);
        $this->assertSame(1, $this->count_rows('notifications', "customer_id = 0 AND type = 'job_dead'"));
    }

    /**
     * `running` had no exit: a PHP fatal or an OOM lands in neither branch of
     * the try/catch, so the row stayed claimed forever — invisible to the due
     * query, the failed list and the retry button.
     */
    public function test_reap_stale_requeues_an_abandoned_claim(): void
    {
        $job_id = JobRunner::enqueue('test_ok', ['n' => 1]);
        $this->abandon($job_id, 1, 60);

        $this->assertSame(0, JobRunner::stats()['pending']);
        $this->assertSame(1, JobRunner::stats()['stale_running'], 'the operator needs to see a claim that is not progressing');

        $this->assertSame(1, JobRunner::reap_stale());
        $this->assertSame('pending', $this->job($job_id)['status']);
        $this->assertStringContainsString('reclaimed', (string) $this->job($job_id)['last_error']);

        // …and it genuinely runs again, which is the whole point of reaping.
        $this->assertSame(1, JobRunner::run_due());
        $this->assertSame('done', $this->job($job_id)['status']);
    }

    public function test_reap_stale_dead_letters_a_job_that_has_already_exhausted_its_attempts(): void
    {
        $job_id = JobRunner::enqueue('test_ok', []);
        $this->abandon($job_id, 5, 60); // attempts == max_attempts

        $this->assertSame(1, JobRunner::reap_stale());
        $this->assertSame('dead', $this->job($job_id)['status'], 'a job that kills its worker every time must not loop forever');
        $this->assertSame(1, $this->count_rows('notifications', "type = 'job_dead'"));
    }

    public function test_reap_stale_leaves_a_healthy_in_flight_claim_alone(): void
    {
        $job_id = JobRunner::enqueue('test_ok', []);
        $this->abandon($job_id, 1, 1); // claimed one minute ago — still working

        $this->assertSame(0, JobRunner::reap_stale());
        $this->assertSame('running', $this->job($job_id)['status']);
        $this->assertSame(0, JobRunner::stats()['stale_running']);
    }

    public function test_run_due_reaps_before_it_dispatches(): void
    {
        $job_id = JobRunner::enqueue('test_ok', ['n' => 2]);
        $this->abandon($job_id, 1, 60);

        // Nothing is pending — only the reaper at the head of the tick can
        // make this job runnable again.
        $this->assertSame(1, JobRunner::run_due());
        $this->assertSame([['n' => 2]], $GLOBALS['__arvrs_job_calls']);
    }

    public function test_failed_lists_dead_retried_and_abandoned_jobs_with_their_payload(): void
    {
        $failing = JobRunner::enqueue('always_fails', ['order_id' => 42]);
        JobRunner::run_due();
        $stuck = JobRunner::enqueue('test_ok', ['order_id' => 43]);
        $this->abandon($stuck, 1, 60);

        $rows = JobRunner::failed();
        $ids  = array_map('intval', array_column($rows, 'id'));
        $this->assertContains($failing, $ids);
        $this->assertContains($stuck, $ids);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('payload', $row, 'the admin must be able to see WHICH order a dead job belongs to');
            $this->assertArrayHasKey('last_error', $row);
        }
    }

    public function test_retry_accepts_a_dead_job_and_a_stale_claim_but_not_a_healthy_one(): void
    {
        $dead = JobRunner::enqueue('test_ok', []);
        $this->db->query('UPDATE ' . JobRunner::table() . " SET status = 'dead', attempts = 5 WHERE id = " . $dead);
        $this->assertTrue(JobRunner::retry($dead));
        $this->assertSame('pending', $this->job($dead)['status']);
        $this->assertSame(0, (int) $this->job($dead)['attempts'], 'a retried job starts its ladder again');

        $stale = JobRunner::enqueue('test_ok', []);
        $this->abandon($stale, 1, 60);
        $this->assertTrue(JobRunner::retry($stale));

        $healthy = JobRunner::enqueue('test_ok', []);
        $this->abandon($healthy, 1, 1);
        $this->assertFalse(JobRunner::retry($healthy), 'a job a live worker is holding must not be yanked out from under it');
    }

    public function test_kill_dead_letters_a_job_and_records_who_did_it(): void
    {
        wp_set_current_user($this->customer(9));
        $job_id = JobRunner::enqueue('test_ok', []);

        $this->assertTrue(JobRunner::kill($job_id));
        $this->assertSame('dead', $this->job($job_id)['status']);
        $this->assertStringContainsString('killed', (string) $this->job($job_id)['last_error']);
        $this->assertSame(1, $this->count_rows('audit_log', "action = 'job.killed'"));

        $done = JobRunner::enqueue('test_ok', []);
        $this->db->query('UPDATE ' . JobRunner::table() . " SET status = 'done' WHERE id = " . $done);
        $this->assertFalse(JobRunner::kill($done), 'a finished job is not killable');
    }

    public function test_prune_removes_finished_receipts_and_keeps_everything_else(): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS);
        $done = JobRunner::enqueue('test_ok', []);
        $dead = JobRunner::enqueue('test_ok', []);
        $this->db->query('UPDATE ' . JobRunner::table() . " SET status = 'done', updated_at = '" . $old . "' WHERE id = " . $done);
        $this->db->query('UPDATE ' . JobRunner::table() . " SET status = 'dead', updated_at = '" . $old . "' WHERE id = " . $dead);

        $this->assertSame(1, JobRunner::prune(90));
        $this->assertNull(JobRunner::detail($done));
        $this->assertNotNull(JobRunner::detail($dead), 'a dead job is evidence, not a receipt');
    }

    /** Put a job in the state a killed worker leaves behind. */
    private function abandon(int $job_id, int $attempts, int $claimed_minutes_ago): void
    {
        $this->db->query($this->db->prepare(
            'UPDATE ' . JobRunner::table() . " SET status = 'running', attempts = %d, claimed_at = %s, updated_at = %s WHERE id = %d",
            $attempts,
            gmdate('Y-m-d H:i:s', time() - $claimed_minutes_ago * 60),
            Helpers::now(),
            $job_id
        ));
    }
}
