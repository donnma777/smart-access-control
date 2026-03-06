<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-option-utils.php';

class TestOptions extends TestCase {
    public function setUp(): void {
        // ensure clean state before each test
        delete_option('ggc_global_featured_display_mode');
    }

    public function testGeneralSettingsIncludesFeaturedDisplayModeDefault() {
        $settings = GGC_Options::get_general_settings();
        $this->assertArrayHasKey('global_featured_display_mode', $settings);
        $this->assertSame('normal', $settings['global_featured_display_mode']);
    }

    public function testGeneralSettingsReflectsSavedValue() {
        update_option('ggc_global_featured_display_mode', 'hide');
        $settings = GGC_Options::get_general_settings();
        $this->assertSame('hide', $settings['global_featured_display_mode']);
    }
}
