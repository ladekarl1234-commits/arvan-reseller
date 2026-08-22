<?php
/**
 * The brand colour used to be declared in six places with two different
 * teals: `Options::BRAND_COLOR` was `#14bfb4` (2.30:1 against white — below
 * the 4.5:1 AA floor `Brand::accessible()` exists to enforce on every value
 * an admin is allowed to save), while `Brand::FALLBACK` and two literals in
 * `Front\Assets` were a different `#0c6960`. The shipped default was less
 * accessible than anything a reseller was permitted to pick themselves.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Admin\Brand;
use ArvanReseller\Support\Options;

final class BrandColorTest extends Arvrs_DbTestCase
{
    public function test_the_shipped_default_itself_clears_the_accessibility_floor(): void
    {
        $ratio = Brand::contrast_with_white(Options::BRAND_COLOR);
        $this->assertGreaterThanOrEqual(Brand::MIN_CONTRAST, $ratio, 'the default must not need Brand::accessible() to rescue it');
    }

    /** One constant, not two: Brand::FALLBACK is not a second value to drift out of sync. */
    public function test_brand_fallback_is_options_brand_color_not_a_second_declaration(): void
    {
        $this->assertSame(Options::BRAND_COLOR, Brand::FALLBACK);
    }

    public function test_accessible_leaves_an_already_passing_colour_untouched(): void
    {
        $result = Brand::accessible(Options::BRAND_COLOR);
        $this->assertFalse($result['adjusted']);
        $this->assertSame(Options::BRAND_COLOR, $result['color']);
    }
}
