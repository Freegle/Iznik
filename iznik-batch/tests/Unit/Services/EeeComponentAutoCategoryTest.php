<?php

namespace Tests\Unit\Services;

use App\Services\EeeComponentService;
use App\Services\EeeSqliteService;
use Tests\TestCase;

/**
 * Comprehensive tests for EeeComponentService::autoCategory().
 *
 * autoCategory() is a pure string-matching function: it takes a raw component
 * description, lowercases and trims it, then applies the CATEGORY_RULES
 * constant (an ordered set of regex patterns) to return one of:
 *   'primary_eee'      — the component itself requires electricity as its primary function
 *   'supplementary_eee'— electrical feature that supports a non-electrical item
 *   'non_electrical'   — purely mechanical / structural component
 *   'unknown'          — no pattern matched; needs manual review
 *
 * All tests use a table-driven approach and a mocked EeeSqliteService,
 * as autoCategory() does not touch the database.
 */
class EeeComponentAutoCategoryTest extends TestCase
{
    private EeeComponentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // autoCategory() does not use $sqlite at all. A simple mock suffices.
        $sqlite = $this->createMock(EeeSqliteService::class);
        $this->service = new EeeComponentService($sqlite);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function assertCategory(string $expected, string $input, string $message = ''): void
    {
        $this->assertSame(
            $expected,
            $this->service->autoCategory($input),
            $message !== '' ? $message : "Category for: {$input}"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION 1: primary_eee — motors, compressors, drives
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider provideMotorAndDriveCases
     */
    public function test_primary_eee_motor_and_drive(string $input): void
    {
        $this->assertCategory('primary_eee', $input);
    }

    public static function provideMotorAndDriveCases(): array
    {
        return [
            'motor'              => ['motor'],
            'drum motor'         => ['drum motor'],
            'fan motor'          => ['fan motor'],
            'pump motor'         => ['pump motor'],
            'compressor'         => ['compressor'],
            'drum assembly'      => ['drum assembly'],
            'electric fan'       => ['electric fan'],
            'electric pump'      => ['electric pump'],
            'mains powered motor'=> ['mains powered motor'],
            'extractor fan'      => ['extractor fan'],
            'motorised drum'     => ['motorised drum'],
            'motorized belt'     => ['motorized belt'],
        ];
    }

    /**
     * @dataProvider provideHeatingElementCases
     */
    public function test_primary_eee_heating_elements(string $input): void
    {
        $this->assertCategory('primary_eee', $input);
    }

    public static function provideHeatingElementCases(): array
    {
        return [
            'heating element'        => ['heating element'],
            'heating elements'       => ['heating elements'],
            'grill element'          => ['grill element'],
            'magnetron'              => ['magnetron'],
            'electric oven'          => ['electric oven'],
            'electric hob'           => ['electric hob'],
            'electric cooker'        => ['electric cooker'],
            'ceramic hob'            => ['ceramic hob'],
            'ceramic glass hob'      => ['ceramic glass hob'],
            'double oven'            => ['double oven'],
            'oven cavity'            => ['oven cavity'],
            'submersible heater'     => ['submersible heater'],
            'aquarium heater'        => ['aquarium heater'],
            'heater'                 => ['heater'],
            'electric kettle'        => ['electric kettle'],
            'microwave oven'         => ['microwave oven'],
            'built-in oven'          => ['built-in oven'],
            'oven (exact)'           => ['oven'],
        ];
    }

    /**
     * @dataProvider provideBatteryAndPowerCases
     */
    public function test_primary_eee_battery_and_power(string $input): void
    {
        $this->assertCategory('primary_eee', $input);
    }

    public static function provideBatteryAndPowerCases(): array
    {
        return [
            'rechargeable battery'    => ['rechargeable battery'],
            'rechargeable lithium'    => ['rechargeable lithium cell'],
            'internal battery'        => ['internal battery'],
            'built-in battery'        => ['built-in battery'],
            'lithium battery'         => ['lithium battery'],
            'battery pack'            => ['battery pack'],
            'battery compartment'     => ['battery compartment'],
            'solar panel'             => ['solar panel'],
        ];
    }

    /**
     * @dataProvider providePrintingAndScanCases
     */
    public function test_primary_eee_printing_and_scanning(string $input): void
    {
        $this->assertCategory('primary_eee', $input);
    }

    public static function providePrintingAndScanCases(): array
    {
        return [
            'print head'              => ['print head'],
            'printing mechanism'      => ['printing mechanism'],
            'laser print'             => ['laser print module'],
            'inkjet print mechanism'  => ['inkjet print mechanism'],
            'flatbed scanner'         => ['flatbed scanner'],
            'automatic document feeder' => ['automatic document feeder'],
        ];
    }

    /**
     * @dataProvider provideTunerAndSignalCases
     */
    public function test_primary_eee_tuner_and_signal_processing(string $input): void
    {
        $this->assertCategory('primary_eee', $input);
    }

    public static function provideTunerAndSignalCases(): array
    {
        return [
            'tuner'           => ['tuner'],
            'signal tuner'    => ['signal tuner'],
            'freeview tuner'  => ['freeview tuner'],
            'dvb-t'           => ['dvb-t'],
            'signal processor'=> ['signal processor'],
            'set-top box'     => ['set-top box'],
            'digital media player'    => ['digital media player'],
            'freeview receiver'       => ['freeview receiver'],
        ];
    }

    /**
     * @dataProvider provideDisplayAndScreenCases
     */
    public function test_primary_eee_display_and_screens(string $input): void
    {
        $this->assertCategory('primary_eee', $input);
    }

    public static function provideDisplayAndScreenCases(): array
    {
        return [
            'lcd display'           => ['lcd display'],
            'led display'           => ['led display'],
            'oled screen'           => ['oled screen'],
            'plasma screen'         => ['plasma screen'],
            'qled display'          => ['qled display'],
            'flat panel display'    => ['flat panel display'],
            'flat-panel display'    => ['flat-panel display'],
            'computer monitor'      => ['computer monitor'],
            'lcd monitor'           => ['lcd monitor'],
            'laptop'                => ['laptop'],
            'flat-screen television'=> ['flat-screen television'],
            'crt display'           => ['crt display'],
            'cathode ray tube'      => ['cathode ray tube'],
            'desktop computer'      => ['desktop computer'],
            'desktop tower'         => ['desktop tower'],
            'display console'       => ['display console'],
            'tv (exact string)'     => ['tv'],
        ];
    }

    /**
     * @dataProvider provideLightingCases
     */
    public function test_primary_eee_lighting(string $input): void
    {
        $this->assertCategory('primary_eee', $input);
    }

    public static function provideLightingCases(): array
    {
        return [
            'led bulb'              => ['led bulb'],
            'led bulbs'             => ['led bulbs'],
            'light bulb'            => ['light bulb'],
            'halogen bulbs'         => ['halogen bulbs'],
            'incandescent bulb'     => ['incandescent bulb'],
            'gu10 bulbs'            => ['gu10 bulbs'],
            'led strip lights'      => ['led strip lights'],
            'led spotlights'        => ['led spotlights'],
            'led mood lighting'     => ['led mood lighting'],
            'lightbulb'             => ['lightbulb'],
            'cfl bulb'              => ['cfl bulb'],
            'compact fluorescent bulb'  => ['compact fluorescent bulb'],
            'fluorescent tube'      => ['fluorescent tube'],
            'aquarium light'        => ['aquarium light'],
            'fairy lights'          => ['fairy lights'],
            'christmas lights'      => ['christmas lights'],
        ];
    }

    /**
     * @dataProvider provideAudioCases
     */
    public function test_primary_eee_audio_components(string $input): void
    {
        $this->assertCategory('primary_eee', $input);
    }

    public static function provideAudioCases(): array
    {
        return [
            'audio amplifier'     => ['audio amplifier'],
            'speaker drivers'     => ['speaker drivers'],
            'speaker cone'        => ['speaker cone'],
            'voice coil'          => ['voice coil'],
            'soundbar'            => ['soundbar'],
            'built-in speakers'   => ['built-in speakers'],
            'woofer'              => ['woofer'],
            'subwoofer'           => ['subwoofer'],
            'loudspeaker drivers' => ['loudspeaker drivers'],
        ];
    }

    /**
     * @dataProvider provideOpticalDriveCases
     */
    public function test_primary_eee_optical_drives(string $input): void
    {
        $this->assertCategory('primary_eee', $input);
    }

    public static function provideOpticalDriveCases(): array
    {
        return [
            'cd drive'            => ['cd drive'],
            'dvd drive'           => ['dvd drive'],
            'blu-ray drive'       => ['blu-ray drive'],
            'cassette deck'       => ['cassette deck'],
            'optical disc drive'  => ['optical disc drive'],
            'hard disk drive'     => ['hard disk drive'],
            'hdd'                 => ['hdd'],
        ];
    }

    /**
     * @dataProvider provideComputingCases
     */
    public function test_primary_eee_computing_hardware(string $input): void
    {
        $this->assertCategory('primary_eee', $input);
    }

    public static function provideComputingCases(): array
    {
        return [
            'graphics card'       => ['graphics card'],
            'expansion card'      => ['expansion card'],
            'webcam'              => ['webcam'],
            'motherboard'         => ['motherboard'],
            'camera'              => ['camera'],
            'image sensor'        => ['image sensor'],
            'ccd image sensor'    => ['ccd image sensor'],
            'cmos sensor'         => ['cmos sensor'],
        ];
    }

    /**
     * @dataProvider provideInputDeviceCases
     */
    public function test_primary_eee_input_devices(string $input): void
    {
        $this->assertCategory('primary_eee', $input);
    }

    public static function provideInputDeviceCases(): array
    {
        return [
            'keyboard'              => ['keyboard'],
            'mouse'                 => ['mouse'],
            'computer mouse'        => ['computer mouse'],
            'wired keyboard'        => ['wired keyboard'],
            'wireless keyboard'     => ['wireless keyboard'],
            'bluetooth mouse'       => ['bluetooth mouse'],
            'usb receiver dongle'   => ['usb receiver dongle'],
            'remote control'        => ['remote control'],
            'tv remote control'     => ['tv remote control'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION 2: supplementary_eee — controls, cables, connectors, indicators
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider provideDisplayAndIndicatorCases
     */
    public function test_supplementary_eee_displays_and_indicators(string $input): void
    {
        $this->assertCategory('supplementary_eee', $input);
    }

    public static function provideDisplayAndIndicatorCases(): array
    {
        return [
            'digital display'         => ['digital display'],
            'digital clock'           => ['digital clock'],
            'digital timer'           => ['digital timer'],
            'display panel'           => ['display panel'],
            'electronic display'      => ['electronic display'],
            'indicator panel'         => ['indicator panel'],
            'indicator light'         => ['indicator light'],
            'power indicator led'     => ['power indicator led'],
            'indicator led'           => ['indicator led'],
            'standby indicator'       => ['standby indicator'],
        ];
    }

    /**
     * @dataProvider provideSwitchAndControlCases
     */
    public function test_supplementary_eee_switches_and_controls(string $input): void
    {
        $this->assertCategory('supplementary_eee', $input);
    }

    public static function provideSwitchAndControlCases(): array
    {
        return [
            'on/off switch'          => ['on/off switch'],
            'power switch'           => ['power switch'],
            'power button'           => ['power button'],
            'start button'           => ['start button'],
            'eject button'           => ['eject button'],
            'reset button'           => ['reset button'],
            'control button'         => ['control button'],
            'push-button'            => ['push-button'],
            'foot pedal'             => ['foot pedal'],
            'safety switch'          => ['safety switch'],
            'heat selector switch'   => ['heat selector switch'],
        ];
    }

    /**
     * @dataProvider provideCableAndConnectorCases
     */
    public function test_supplementary_eee_cables_and_connectors(string $input): void
    {
        $this->assertCategory('supplementary_eee', $input);
    }

    public static function provideCableAndConnectorCases(): array
    {
        return [
            'power cable'           => ['power cable'],
            'power cord'            => ['power cord'],
            'mains power flex'      => ['mains power flex'],
            'electrical cable'      => ['electrical cable'],
            'hdmi port'             => ['hdmi port'],
            'usb port'              => ['usb port'],
            'usb cable'             => ['usb cable'],
            '3.5mm jack'            => ['3.5mm jack'],
            'audio jack'            => ['audio jack'],
            'scart socket'          => ['scart socket'],
            'coaxial cable'         => ['coaxial cable'],
            'charging cable'        => ['charging cable'],
            'charging port'         => ['charging port'],
            'power adapter'         => ['power adapter'],
            'speaker cable'         => ['speaker cable'],
            'speaker terminals'     => ['speaker terminals'],
            'rca connector'         => ['rca connector'],
        ];
    }

    /**
     * @dataProvider provideLightingSupplementaryCases
     */
    public function test_supplementary_eee_incidental_lighting(string $input): void
    {
        $this->assertCategory('supplementary_eee', $input);
    }

    public static function provideLightingSupplementaryCases(): array
    {
        return [
            'led light'          => ['led light'],
            'led lighting'       => ['led lighting'],
            'interior lighting'  => ['interior lighting'],
            'oven light'         => ['oven light'],
            'built-in light'     => ['built-in light'],
            'hood light'         => ['hood light'],
        ];
    }

    /**
     * @dataProvider provideIgnitionAndSensorCases
     */
    public function test_supplementary_eee_ignition_and_sensors(string $input): void
    {
        $this->assertCategory('supplementary_eee', $input);
    }

    public static function provideIgnitionAndSensorCases(): array
    {
        return [
            'electronic ignition'   => ['electronic ignition'],
            'electric ignition'     => ['electric ignition'],
            'pulse sensor'          => ['pulse sensor'],
            'heart rate sensor'     => ['heart rate sensor'],
            'infrared receiver'     => ['infrared receiver'],
            'infrared emitter'      => ['infrared emitter'],
            'infrared transmitter'  => ['infrared transmitter'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION 3: non_electrical — mechanical / structural components
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider provideNonElectricalCases
     */
    public function test_non_electrical_mechanical_components(string $input): void
    {
        $this->assertCategory('non_electrical', $input);
    }

    public static function provideNonElectricalCases(): array
    {
        return [
            'drain hose'            => ['drain hose'],
            'door seal'             => ['door seal'],
            'glass turntable'       => ['glass turntable'],
            'turntable plate'       => ['turntable plate'],
            'detergent drawer'      => ['detergent drawer'],
            'door lock mechanism'   => ['door lock mechanism'],
            'door interlock mechanism'=> ['door interlock mechanism'],
            'door handle'           => ['door handle'],
            'door hinge'            => ['door hinge'],
            'mechanical timer'      => ['mechanical timer'],
            'rotary control knob'   => ['rotary control knob'],
            'warning label'         => ['warning label'],
            'speaker grille'        => ['speaker grille'],
            'air vent'              => ['air vent'],
            'ventilation grille'    => ['ventilation grille'],
            'lamp shade'            => ['lamp shade'],
            'viewing window'        => ['viewing window'],
            'volume knob'           => ['volume knob'],
            'filter'                => ['filter'],
            'gas burners'           => ['gas burners'],
            'gas oven'              => ['gas oven'],
            'internal combustion engine' => ['internal combustion engine'],
            'camera lens'           => ['camera lens'],
            'dust canister'         => ['dust canister'],
            'extension wand'        => ['extension wand'],
            'fuel tank'             => ['fuel tank'],
            'cartridge'             => ['cartridge'],
            'gooseneck arm'         => ['gooseneck arm'],
            'energy rating label'   => ['energy rating label'],
            'oven doors'            => ['oven doors'],
            'drum interior'         => ['drum interior'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION 4: unknown — unmatched inputs
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider provideUnknownCases
     */
    public function test_unknown_for_unrecognised_inputs(string $input): void
    {
        $this->assertCategory('unknown', $input);
    }

    public static function provideUnknownCases(): array
    {
        return [
            'empty string'              => [''],
            'whitespace only'           => ['   '],
            'arbitrary text'            => ['some random component xyz'],
            'numbers only'              => ['12345'],
            'gibberish'                 => ['qwerty asdf zxcv'],
            'partial word (no match)'   => ['mot'],   // 'motor' needs word boundary
            'compress (no match)'       => ['compress'],  // not 'compressor'
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION 5: Case insensitivity
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider provideCaseInsensitiveCases
     */
    public function test_case_insensitive_matching(string $input, string $expected): void
    {
        $this->assertCategory($expected, $input);
    }

    public static function provideCaseInsensitiveCases(): array
    {
        return [
            'MOTOR uppercase'           => ['MOTOR', 'primary_eee'],
            'Motor mixed'               => ['Motor', 'primary_eee'],
            'LCD DISPLAY uppercase'     => ['LCD DISPLAY', 'primary_eee'],
            'Lcd Display title case'    => ['Lcd Display', 'primary_eee'],
            'HEATING ELEMENT upper'     => ['HEATING ELEMENT', 'primary_eee'],
            'POWER CABLE upper'         => ['POWER CABLE', 'supplementary_eee'],
            'DRAIN HOSE upper'          => ['DRAIN HOSE', 'non_electrical'],
            'DOOR SEAL upper'           => ['DOOR SEAL', 'non_electrical'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION 6: Whitespace trimming
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider provideWhitespaceCases
     */
    public function test_leading_and_trailing_whitespace_is_trimmed(string $input, string $expected): void
    {
        $this->assertCategory($expected, $input, "Whitespace-padded: '{$input}'");
    }

    public static function provideWhitespaceCases(): array
    {
        return [
            'leading spaces motor'       => ['  motor', 'primary_eee'],
            'trailing spaces motor'      => ['motor  ', 'primary_eee'],
            'both spaces motor'          => ['  motor  ', 'primary_eee'],
            'leading spaces drain hose'  => ['  drain hose  ', 'non_electrical'],
            'tab-padded power cable'     => ["\tpower cable\t", 'supplementary_eee'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION 7: Boundary / disambiguation tests
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Words that should NOT trigger primary_eee despite containing a root.
     * E.g. "camera lens" is non_electrical, not primary_eee (camera alone is primary).
     */
    public function test_camera_lens_is_non_electrical_not_camera(): void
    {
        // 'camera' pattern has a negative lookahead for (lens|bag|case|...)
        $this->assertCategory('non_electrical', 'camera lens');
    }

    /**
     * "backlit keyboard" is still primary_eee because the primary_eee rule is:
     *   /\bkeyboard\b(?!.*backlight|.*indicator|.*backlit|.*backlighting)/i
     * The negative lookahead checks for these words AFTER "keyboard". In
     * "backlit keyboard", "backlit" appears BEFORE "keyboard", so the lookahead
     * does not fire — the pattern matches and primary_eee is returned.
     *
     * By contrast, "keyboard backlight" has "backlight" AFTER "keyboard", which
     * blocks the primary_eee pattern and falls through to supplementary_eee.
     */
    public function test_backlit_keyboard_is_primary(): void
    {
        // "backlit" is before "keyboard" → negative lookahead does NOT fire → primary_eee
        $this->assertCategory('primary_eee', 'backlit keyboard');
    }

    /**
     * "keyboard backlight" — "backlight" is after "keyboard", so the primary_eee
     * negative lookahead fires and blocks the match.  It then falls through to
     * supplementary_eee via '/\bkeyboard (backlight|backlighting)\b/i'.
     */
    public function test_keyboard_backlight_is_supplementary(): void
    {
        $this->assertCategory('supplementary_eee', 'keyboard backlight');
    }

    /**
     * "keyboard with backlight" should be primary_eee — primary_eee has an explicit
     * pattern: '/\bkeyboard with back(lit|light)\b/i' which takes precedence.
     */
    public function test_keyboard_with_backlight_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'keyboard with backlight');
    }

    /**
     * "filter" is non_electrical, but "filter electronic" should NOT match
     * the non_electrical filter pattern (which has a negative lookahead).
     */
    public function test_filter_alone_is_non_electrical(): void
    {
        $this->assertCategory('non_electrical', 'filter');
    }

    /**
     * "motherboard visible through side window" should be non_electrical
     * (the pattern for standalone motherboard has a negative lookahead for this phrase).
     */
    public function test_motherboard_visible_through_window_is_non_electrical(): void
    {
        $this->assertCategory('non_electrical', 'motherboard visible through side window');
    }

    /**
     * "oven" alone should be primary_eee — there is an exact ^oven$ pattern.
     * "oven doors" should be non_electrical — different pattern.
     */
    public function test_exact_oven_is_primary_eee(): void
    {
        $this->assertCategory('primary_eee', 'oven');
    }

    public function test_oven_door_is_non_electrical(): void
    {
        $this->assertCategory('non_electrical', 'oven doors');
    }

    /**
     * "scart socket" is supplementary (just the port),
     * "scart-to-hdmi" is primary (the active converter device).
     */
    public function test_scart_socket_is_supplementary(): void
    {
        $this->assertCategory('supplementary_eee', 'scart socket');
    }

    public function test_scart_to_hdmi_converter_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'scart-to-hdmi converter');
    }

    /**
     * "door handle" — non_electrical; the pattern negative-lookaheads "with ... control"
     * so "door handle with controls" should NOT be non_electrical.
     */
    public function test_door_handle_alone_is_non_electrical(): void
    {
        $this->assertCategory('non_electrical', 'door handle');
    }

    /**
     * "rotary control knob" is non_electrical; the pattern is
     * '/\brotary control knob\b/' in non_electrical and the qualifier
     * for supplementary only matches rotary + specific suffixes.
     */
    public function test_rotary_control_knob_is_non_electrical(): void
    {
        $this->assertCategory('non_electrical', 'rotary control knob');
    }

    /**
     * "viewing window" (plain) is non_electrical,
     * but "viewing window with backlit" should be supplementary.
     */
    public function test_plain_viewing_window_is_non_electrical(): void
    {
        $this->assertCategory('non_electrical', 'viewing window');
    }

    /**
     * "tv" alone (exact string) should be primary_eee via the '/^tv$/i' pattern.
     */
    public function test_exact_tv_string_is_primary_eee(): void
    {
        $this->assertCategory('primary_eee', 'tv');
    }

    /**
     * "speaker" alone should be primary_eee.
     * "speaker grille" should be non_electrical (just the cover).
     * "speaker cable" should be supplementary (wiring, not primary).
     */
    public function test_speaker_exact_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'speaker');
    }

    public function test_speakers_exact_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'speakers');
    }

    public function test_speaker_grille_is_non_electrical(): void
    {
        $this->assertCategory('non_electrical', 'speaker grille');
    }

    public function test_speaker_cable_is_supplementary(): void
    {
        $this->assertCategory('supplementary_eee', 'speaker cable');
    }

    /**
     * Mouse — 'mouse' alone is primary_eee (the '/\bmouse\b(?!.*pad|.*trap)/' pattern).
     * "mouse pad" should NOT match (negative lookahead) → unknown.
     * "mouse trap" should NOT match either → unknown.
     */
    public function test_mouse_alone_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'mouse');
    }

    public function test_mouse_pad_is_unknown(): void
    {
        $this->assertCategory('unknown', 'mouse pad');
    }

    public function test_mouse_trap_is_unknown(): void
    {
        $this->assertCategory('unknown', 'mouse trap');
    }

    /**
     * "led (inferred from appliance type)" (exact) should be supplementary_eee,
     * not primary, because a standalone LED descriptor merely confirms another
     * device is EEE, not that the LED itself is the primary item.
     */
    public function test_led_inferred_from_appliance_type_is_supplementary(): void
    {
        $this->assertCategory('supplementary_eee', 'led (inferred from appliance type)');
    }

    /**
     * Order precedence: primary_eee rules are checked before supplementary_eee.
     * "tuner" appears in primary_eee; "display panel" in supplementary_eee.
     * A compound description containing both should return primary_eee since
     * primary_eee patterns are checked first.
     */
    public function test_primary_takes_precedence_over_supplementary(): void
    {
        $this->assertCategory('primary_eee', 'tuner display panel');
    }

    /**
     * "fabric" is non_electrical; make sure it doesn't accidentally match
     * any electrical patterns.
     */
    public function test_fabric_is_non_electrical(): void
    {
        $this->assertCategory('non_electrical', 'fabric');
    }

    /**
     * Aquarium components disambiguation:
     * "aquarium pump" and "aquarium light" are primary_eee (powered),
     * "aquarium heater" is primary_eee.
     */
    public function test_aquarium_pump_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'aquarium pump');
    }

    public function test_aquarium_light_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'aquarium light');
    }

    /**
     * "image sensor" is primary (camera's key component);
     * "ccd image sensor" and "cmos image sensor" also primary.
     */
    public function test_image_sensor_variants_are_primary(): void
    {
        $this->assertCategory('primary_eee', 'image sensor');
        $this->assertCategory('primary_eee', 'ccd image sensor');
        $this->assertCategory('primary_eee', 'cmos image sensor');
    }

    /**
     * Water pump and water dispenser — primary_eee (motor-driven).
     */
    public function test_water_pump_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'water pump');
    }

    public function test_water_dispenser_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'water dispenser');
    }

    /**
     * "power tool" is primary_eee via the explicit pattern.
     */
    public function test_power_tool_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'power tool');
    }

    /**
     * Baby monitor components.
     */
    public function test_baby_monitor_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'baby monitor');
    }

    /**
     * "gas ignition" and "electric ignition" disambiguation:
     * - gas ignition = supplementary_eee (supplemental igniter on a gas appliance)
     * - electric ignition = supplementary_eee
     */
    public function test_gas_ignition_is_supplementary(): void
    {
        $this->assertCategory('supplementary_eee', 'gas ignition');
    }

    public function test_electric_ignition_is_supplementary(): void
    {
        $this->assertCategory('supplementary_eee', 'electric ignition');
    }

    /**
     * "built-in light" is supplementary (incidental interior lighting);
     * "built-in led panel" is primary (primary display function).
     */
    public function test_builtin_light_is_supplementary(): void
    {
        $this->assertCategory('supplementary_eee', 'built-in light');
    }

    public function test_builtin_led_panel_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'built-in led panel');
    }

    /**
     * "on/off" alone is supplementary (it's just a control indicator).
     */
    public function test_on_off_alone_is_supplementary(): void
    {
        $this->assertCategory('supplementary_eee', 'on/off');
    }

    /**
     * "hob" alone is primary_eee (the negative lookahead ensures gas/petrol words
     * are excluded).
     */
    public function test_hob_alone_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'hob');
    }

    /**
     * Sensor mat / sensor pad — primary_eee (movement or breathing detection device).
     */
    public function test_sensor_pad_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'sensor pad');
    }

    public function test_movement_detection_pad_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'movement detection pad');
    }

    /**
     * "streaming device" is primary_eee.
     */
    public function test_streaming_device_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'streaming device');
    }

    /**
     * "tonearm" is primary_eee (the tonearm is the primary mechanical-electrical
     * interface in a turntable).
     */
    public function test_tonearm_is_primary(): void
    {
        $this->assertCategory('primary_eee', 'tonearm');
    }
}
