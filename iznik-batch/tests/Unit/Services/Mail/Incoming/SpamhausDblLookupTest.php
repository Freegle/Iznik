<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\SpamhausDblLookup;
use Tests\TestCase;

class SpamhausDblLookupTest extends TestCase
{
    public function test_answers_from_the_list_it_was_given(): void
    {
        $lookup = new SpamhausDblLookup(listed: ['Bad.Example.com']);

        $this->assertTrue($lookup->isListed('bad.example.com'));
        $this->assertTrue($lookup->isListed('BAD.EXAMPLE.COM'));
        $this->assertFalse($lookup->isListed('good.example.com'));
        $this->assertFalse((new SpamhausDblLookup(listed: []))->isListed('bad.example.com'));
    }

    public function test_asks_dns_for_the_host_under_the_dbl_zone(): void
    {
        $lookup = new class extends SpamhausDblLookup {
            public array $asked = [];

            protected function aRecords(string $name): array
            {
                $this->asked[] = $name;

                return $name === 'bad.example.com.dbl.spamhaus.org' ? [['type' => 'A', 'ip' => '127.0.1.2']] : [];
            }
        };

        $this->assertTrue($lookup->isListed('bad.example.com'));
        $this->assertFalse($lookup->isListed('good.example.com'));
        $this->assertSame(['bad.example.com.dbl.spamhaus.org', 'good.example.com.dbl.spamhaus.org'], $lookup->asked);
    }

    public function test_the_container_builds_the_real_lookup_and_its_dns_call_runs(): void
    {
        // Every test gets a lookup with a list bound in TestCase, so without this the
        // class production resolves, and its dns_get_record line, would run nowhere.
        $this->app->forgetInstance(SpamhausDblLookup::class);
        $lookup = $this->app->make(SpamhausDblLookup::class);

        $listed = (new \ReflectionProperty(SpamhausDblLookup::class, 'listed'))->getValue($lookup);
        $this->assertNull($listed, 'production should ask DNS, not consult a list');

        // A name under .invalid never resolves (RFC 6761), so this runs the real
        // DNS call without asking Spamhaus anything and without depending on the
        // network's answer: no records, quickly.
        $aRecords = new \ReflectionMethod(SpamhausDblLookup::class, 'aRecords');
        $this->assertSame([], $aRecords->invoke($lookup, 'freegle-test.invalid'));
    }
}
