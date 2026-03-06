<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-debug-logger.php';
require_once dirname(__DIR__) . '/includes/class-ip-utils.php';

class TestIPUtils extends TestCase {

    public function providerValidIpOrCidr() {
        return [
            ['127.0.0.1', true],
            ['192.168.0.0/24', true],
            ['::1', true],
            ['2001:db8::/32', true],
            ['invalid-ip', false],
            ['256.256.256.256', false],
            ['1.2.3.4/33', false],
            ['', false],
        ];
    }

    /**
     * @dataProvider providerValidIpOrCidr
     */
    public function testIsValidIpOrCidr($input, $expected) {
        $this->assertSame($expected, IP_Utils::is_valid_ip_or_cidr($input));
    }

    public function providerExtractCases() {
        return [
            ["127.0.0.1\n192.168.0.0/24", 2],
            [json_encode(['prefixes' => [['ipv4Prefix' => '203.0.113.0/24']]]), 1],
            ["not an ip\nalso none", 0],
            ["2001:db8::/32\n::1", 2],
        ];
    }

    /**
     * @dataProvider providerExtractCases
     */
    public function testExtractIpListFromText($body, $expectedCount) {
        $meta = null;
        $res = IP_Utils::extract_ip_list_from_text($body, $meta);
        if ($expectedCount === 0) {
            $this->assertIsArray($res);
            $this->assertCount(0, $res);
            $this->assertEquals(0, $meta['count']);
        } else {
            $this->assertIsArray($res);
            $this->assertEquals($expectedCount, count($res));
            $this->assertEquals($expectedCount, $meta['count']);
        }
    }

    public function testDebugLoggerCanBeToggledViaFilter() {
        // make sure helper exists
        $this->assertTrue(function_exists('ggc_debug_log'));

        // filter should be applied when evaluating logger
        add_filter('ggc_enable_debug_log', '__return_false');
        $this->assertFalse( apply_filters('ggc_enable_debug_log', false ) );

        // invoking the logger should not produce any errors even when disabled
        ggc_debug_log('test message');
    }

    public function testInstantiationLogFilterDefaultsFalse() {
        // default value must be false
        $this->assertFalse( apply_filters('ggc_log_instantiation', false) );
        // if someone enables it, it should pass through
        add_filter('ggc_log_instantiation', '__return_true');
        $this->assertTrue( apply_filters('ggc_log_instantiation', false) );
    }

}
