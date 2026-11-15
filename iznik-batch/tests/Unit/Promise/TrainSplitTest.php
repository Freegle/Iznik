<?php

namespace Tests\Unit\Promise;

use App\Console\Commands\Promise\TrainPromiseClassifierCommand;
use PHPUnit\Framework\TestCase;

class TrainSplitTest extends TestCase
{
    private function dataset(int $rooms, int $rowsPerRoom = 3): array
    {
        $d = ['samples' => [], 'labels' => [], 'groups' => [], 'endTurns' => [], 'promiseTurns' => [], 'postTypes' => []];
        for ($room = 1; $room <= $rooms; $room++) {
            for ($turn = 0; $turn < $rowsPerRoom; $turn++) {
                $d['samples'][] = "room $room turn $turn";
                $d['labels'][] = 0;
                $d['groups'][] = $room;
                $d['endTurns'][] = $turn;
                $d['promiseTurns'][] = -1;
                $d['postTypes'][] = 'Offer';
            }
        }

        return $d;
    }

    private function split(array $dataset, float $fraction, int $seed): array
    {
        $method = new \ReflectionMethod(TrainPromiseClassifierCommand::class, 'splitByRoom');
        $method->setAccessible(true);

        return $method->invoke(new TrainPromiseClassifierCommand(), $dataset, $fraction, $seed);
    }

    public function test_same_seed_gives_same_test_rooms(): void
    {
        $dataset = $this->dataset(40);

        [, $testA] = $this->split($dataset, 0.25, 7);
        [, $testB] = $this->split($dataset, 0.25, 7);

        $this->assertSame($testA['groups'], $testB['groups']);
        $this->assertCount(10, array_unique($testA['groups']));
    }

    public function test_different_seed_gives_different_test_rooms(): void
    {
        $dataset = $this->dataset(40);

        [, $testA] = $this->split($dataset, 0.25, 1);
        [, $testB] = $this->split($dataset, 0.25, 2);

        $this->assertNotSame($testA['groups'], $testB['groups']);
    }

    public function test_no_room_is_in_both_sets(): void
    {
        [$train, $test] = $this->split($this->dataset(40), 0.25, 3);

        $this->assertSame([], array_values(array_intersect($train['groups'], $test['groups'])));
        $this->assertCount(120, array_merge($train['samples'], $test['samples']));
    }

    public function test_split_with_too_few_rooms_fails_clearly(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->split($this->dataset(1), 0.2, 1);
    }
}
