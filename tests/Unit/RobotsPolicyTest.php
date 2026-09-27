<?php

namespace Tests\Unit;

use App\Services\RobotsPolicy;
use PHPUnit\Framework\TestCase;

class RobotsPolicyTest extends TestCase
{
    public function test_specific_googlebot_group_overrides_wildcard_and_longest_rule_wins(): void
    {
        $policy = new RobotsPolicy();

        $groups = $policy->parse(<<<TXT
User-agent: *
Disallow: /private/
Allow: /private/public/

User-agent: Googlebot
Disallow: /blocked/
Allow: /blocked/preview/
TXT);

        $this->assertTrue($policy->decision('https://example.com/private/secret', $groups)['allowed']);
        $this->assertFalse($policy->decision('https://example.com/blocked/page', $groups)['allowed']);
        $this->assertTrue($policy->decision('https://example.com/blocked/preview/item', $groups)['allowed']);
    }

    public function test_wildcard_group_is_used_when_no_specific_group_exists(): void
    {
        $policy = new RobotsPolicy();
        $groups = $policy->parse("User-agent: *\nDisallow: /tmp/*\nAllow: /tmp/public$\n");

        $blocked = $policy->decision('https://example.com/tmp/file', $groups, 'Googlebot');
        $allowed = $policy->decision('https://example.com/tmp/public', $groups, 'Googlebot');

        $this->assertFalse($blocked['allowed']);
        $this->assertSame('/tmp/*', $blocked['matchedRule']);
        $this->assertTrue($allowed['allowed']);
    }

    public function test_longer_wildcard_rule_beats_shorter_allow_rule(): void
    {
        $policy = new RobotsPolicy();
        $groups = $policy->parse("User-agent: *\nAllow: /page\nDisallow: /*.htm\n");

        $decision = $policy->decision('https://example.com/page.htm', $groups, 'Googlebot');

        $this->assertFalse($decision['allowed']);
        $this->assertSame('/*.htm', $decision['matchedRule']);
    }

}
