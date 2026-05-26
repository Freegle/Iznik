<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Component knowledge base for deterministic EEE classification.
 *
 * The model observes electrical components in images; this service maps those
 * raw strings to canonical component types with categories:
 *   primary_eee      — primary function of the item requires electricity (motor, compressor, heating element)
 *   supplementary_eee — incidental electrical parts (clock, ignition, indicator lights)
 *   non_electrical   — mechanical/structural parts with no electrical function
 *   unknown          — needs manual review
 *
 * EEE decision rule:
 *   ANY primary_eee component present  → is_eee = true
 *   Only supplementary_eee components  → is_eee deferred to text signals
 *   No EEE components at all           → is_eee = false
 */
class EeeComponentService
{
    protected const EMBEDDING_MODEL = 'text-embedding-3-small';
    protected const EMBEDDING_DIMS  = 1536;
    protected const SIMILARITY_THRESHOLD = 0.60;

    /** Keyword rules for initial auto-categorisation (regex on lowercase canonical name). */
    protected const CATEGORY_RULES = [
        'primary_eee' => [
            // Motor / drive
            '/\bmotor\b/',
            '/\bcompressor\b/',
            '/\bdrum assembly\b/',
            '/\belectric fan\b/',
            '/\bfan motor\b/',
            '/\bpump motor\b/',
            '/\belectric pump\b/',
            '/mains.?powered motor/',
            // Heating as primary function
            '/\bheating element(s)?\b/',
            '/\bgrill element\b/',
            '/\bquartz.*grill\b/',
            '/\bmagnetron\b/',
            '/\belectric (oven|hob|cooker)\b/',
            '/\bceramic (glass )?hob\b/',
            '/\bceramic\/glass hob\b/',
            '/\belectric ceramic\b/',
            '/\belectric.*hob\b/',
            '/\bhob heating element\b/',
            '/\bdouble oven\b/',
            '/\boven cavity\b/',
            '/\boven with.*element\b/',
            '/mains.?powered.*oven/',
            '/mains.?powered.*microwave/',
            '/mains.*cooker\b/',
            '/\bmains electric cooker\b/',
            '/\bsubmersible heater\b/',
            '/\baquarium heater\b/',
            '/\bheater\b/',
            // Batteries / power storage (primary function of laptops, tablets, cordless tools)
            '/\brechargeable battery\b/',
            '/\brechargeable lithium/',
            '/\binternal.*battery\b/',
            '/\bbuilt.in.*battery\b/',
            '/\blithium.*battery\b/',
            '/\bbattery pack\b/',
            // Printing mechanisms (primary function of printers)
            '/print(ing)? (head|mechanism|engine)/',
            '/\blaser print/',
            '/\binkjet print/',
            '/mains.?powered.*print/',
            // Signal processing / tuners (primary function of set-top boxes, radios)
            '/\btuner\b/',
            '/\bsignal tuner\b/',
            '/\bfreeview tuner\b/',
            '/\bdvb.t\b/',
            '/\bsignal processor\b/',
            // Image sensors (primary function of cameras)
            '/\bimage sensor\b/',
            '/\b(ccd|cmos) (image )?sensor\b/',
            // Chain/trigger for power tools
            '/\btrigger switch\b/',
            '/\bon\/off trigger\b/',
            // Display panels as primary function (TVs / monitors — model uses v1.4.2 inference suffix)
            '/\bbuilt.in (lcd|led) (panel|screen|display)\b/i',
            '/primary EEE component/i',
            // LCD/LED/plasma display screens (all TV and monitor variants)
            '/\b(lcd|led|oled|plasma|qled).*(display|flat.?panel|screen)\b/i',
            '/\bflat.?panel (display|screen)\b/i',
            '/\bactive (lcd|display) screen\b/i',
            '/\blcd (display |flat panel |)screen\b/i',
            '/\blcd\/led (display|screen|flat panel)\b/i',
            '/\bled.?backlit flat panel\b/i',
            '/\blarge flat.?panel display\b/i',
            '/\bcomputer monitor\b/i',
            '/\blaptop\b/i',
            // Audio transducers (speakers, hi-fi)
            '/\baudio amplifier\b/i',
            '/\bspeaker drivers?\b/i',
            '/\bspeaker drive units?\b/i',
            '/\bspeaker cone\b/i',
            '/\bvoice coil\b/i',
            '/\bbuilt.in amplifier unit\b/i',
            '/\bbuilt.in (phono preamp|phono amplifier)\b/i',
            '/\bsoundbar\b/i',
            // Suction motors (vacuums)
            '/\belectric suction motor\b/i',
            '/\bsuction motor\b/i',
            '/\bbrush roll.*electrically driven\b/i',
            '/\belectrically driven brush\b/i',
            // Light-emitting primary elements (LED/CFL bulbs, lamps)
            '/\blight.emitting element\b/i',
            '/\bLED (chip|element|driver)\b/i',
            '/\blight.emitting diode element\b/i',
            // CFL/halogen/incandescent/LED bulbs (the bulb IS the item being offered)
            '/\bcfl (bulb|spiral|compact|energy.sav)/i',
            '/\bcompact fluorescent (bulb|lamp)/i',
            '/\bhalogen bulbs?\b/i',
            '/\bcandle.shaped (halogen|incandescent) (light )?bulbs?\b/i',
            '/\bincandescent bulb\b/i',
            '/\b(led|light) bulbs?\b/i',
            '/\bgu10 bulbs?\b/i',
            '/\bmr16 bulbs?\b/i',
            // LED effect fires (primary function = the electric light/heat display)
            '/\bflame effect (light|led|log)\b/i',
            '/\bled.*(flame|effect) (fire|lighting)\b/i',
            '/\bsimulated.*fire (display|effect)\b/i',
            '/\bled effect fire\b/i',
            '/\bled\/electric flame effect\b/i',
            // Optical drives / cassette decks (motor-driven = primary)
            '/\b(cd|dvd|blu.?ray) (drive|player|mechanism|tray|slot|disc)\b/i',
            '/\bcd (disc |slot.?loading |top.?loading )?(player|mechanism|drive)\b/i',
            '/\bcassette deck\b/i',
            '/\bcassette.*mechanism\b/i',
            '/\bbuilt.in dvd (player|drive)\b/i',
            // Baby monitors (radio transmitter/receiver = primary)
            '/\bbaby monitor\b/i',
            '/\bbaby (unit|camera unit|monitor unit)\b/i',
            '/\bbuilt.in radio transmitter\b/i',
            // Backlights (display component for TVs/monitors)
            '/\bbacklight (unit|system|panel)\b/i',
            '/\bbacklighting (system|unit)\b/i',
            '/\bled backlight system\b/i',
            // Scanner/ADF
            '/\bflatbed scanner\b/i',
            '/\bscanner (flatbed|unit|lamp)\b/i',
            '/\bautomatic document feeder\b/i',
            '/\badf.*mechanism\b/i',
            // Ceramic/induction hob variants
            '/ceramic\/induction (glass )?hob/i',
            // Camera units
            '/\bcamera (unit|module|sensor|transmitter)\b/i',
            // Battery compartments (imply battery is the primary power source)
            '/\bbattery compartment\b/i',
            // Battery-powered functional devices
            '/battery.?powered (electronic|computer|display|activity|interactive|musical|sound module|toy|unit)/i',
            // Set-top boxes (explicit — tuner already covered but verbose model descriptions)
            '/\bset.?top box\b/i',
            // Solar panels
            '/\bsolar panel\b/i',
            // Aquarium/water equipment (motor-driven)
            '/\baquarium (pump|light|filter)\b/i',
            '/\bwater pump\b/i',
            '/\bwater dispenser (unit)?\b/i',
            '/\bultrasonic (transducer|mist generator)\b/i',
            // Sensor pads (movement/breathing monitor = primary function)
            '/\b(movement|breathing) (detection |)(pad|mat|sensor)\b/i',
            '/\bsensor (pad|mat).*(movement|detection|breathing)\b/i',
            // Built-in speakers (audio transducer = primary, even when described as "built-in")
            '/\bbuilt.in (stereo )?speakers?\b/i',
            '/\bwoofer\b/i',
            // Display screens (all-in-one computers, built-in)
            '/\ball.in.one computer\b/i',
            '/\bbuilt.in (display|screen)\b/i',
            '/\bbuilt.in (dvd\/vcr|dvd|vcr) combo\b/i',
            // Graphics cards / expansion cards (primary computing component)
            '/\bgraphics card\b/i',
            '/\bexpansion card\b/i',
            // Webcams / cameras
            '/\bwebcam\b/i',
            // Motorised/motorized items (anything explicitly motorised = primary EEE)
            '/motori[sz](ed|ing)\b/i',
            // Voltage converters / transformers (conversion IS the primary function)
            '/\bvoltage (converter|transformer) unit\b/i',
            // LED strip/under-cabinet lighting (when the item IS the lights)
            '/\bunder.cabinet led strip\b/i',
            '/\bled strip lights?\b/i',
            '/\bvisible led smd chips?\b/i',
            '/\b12v.*battery (system|pack)\b/i',
            // Remote controls (battery-powered EEE)
            '/\b(universal |sky |tv )?remote control\b/i',
            // Wired/wireless peripherals (keyboard, mouse = EEE devices)
            '/\b(wired|wireless|usb|bluetooth) (keyboard|mouse|optical mouse|usb mouse|usb keyboard)\b/i',
            '/\bwired (keyboard|optical mouse|usb keyboard|usb mouse)\b/i',
            '/\busb receiver dongle\b/i',
            // Built-in electronic modules
            '/\bbuilt.in electronic.*module\b/i',
            '/\bbuilt.in sound.*module\b/i',
            // Activity toys with electronics (likely battery-powered panels)
            '/activity.*(panel|toy|board).*(button|light|battery.powered)/i',
            '/battery.*(or mains).*(powered|figure|animated|motorised)/i',
            '/\bbattery.powered.*wand\b/i',
            '/\bbattery.powered.*torch\b/i',
            // Built-in ovens (primary cooking function)
            '/\bbuilt.in (oven|cooker|stainless steel oven)\b/i',
            // WCS CCFL backlight (obsolete TV backlight = display component)
            '/ccfl backlight\b/i',
            // CRT display tubes (old TV/monitor primary component)
            '/\bcrt (display|picture|screen|television|tv)\b/i',
            '/\bcathode ray tube\b/i',
            '/\binternal cathode ray tube\b/i',
            // Desktop computers / tower PCs
            '/\bdesktop (computer|pc|tower|tower pc)\b/i',
            '/\bdesktop tower\b/i',
            // Hard disk drives (motor-driven storage)
            '/\bhard disk drive\b/i',
            '/\bhdd\b/i',
            // Digital media player / Freeview receiver
            '/\bdigital media player\b/i',
            '/\bfreeview.*receiver\b/i',
            '/\bdigital.*receiver unit\b/i',
            // Electric fires / flame effects
            '/\belectric (fire|flame effect|fireplace|heating unit)\b/i',
            '/\belectric fire (flame|insert|heating)\b/i',
            '/\bflame effect (heating|lighting|unit|display|mechanism|bulb)\b/i',
            '/\belectric flame effect\b/i',
            '/\bdecorative (coal|flame).*(led|lamp|illuminat)/i',
            '/\billuminated disco.?ball\b/i',
            // Electric kettle
            '/\belectric kettle\b/i',
            // Extractor fan (fan motor = primary function)
            '/\bextractor fan\b/i',
            '/\bfloor type selector switch\b/i',
            // Floppy disk drive
            '/\bfloppy disk drive\b/i',
            // Fluorescent tubes (when the tube IS the item)
            '/\bfluorescent tube (lamp|light)?\b/i',
            '/\bfluorescent tube\b/i',
            // Full keyboards (EEE input devices)
            '/\bfull qwerty keyboard\b/i',
            '/\bintegrated keyboard\b/i',
            // Glass ceramic / induction cooktop
            '/\bglass ceramic cooktop\b/i',
            '/\b(glass |)ceramic cooktop\b/i',
            // Graphic equalizer (amplifier = primary)
            '/\bgraphic equalizer\b/i',
            // Halogen / GU10 spotlight bulbs (bulbs = EEE items)
            '/\bhalogen (spotlight|reflector|linear|capsule|candle) bulbs?\b/i',
            '/\bhalogen.*gu10\b/i',
            '/\bgu10 (halogen|led) spotlight\b/i',
            '/\bgu10 halogen spotlight\b/i',
            '/\bgu10 led spotlight bulb\b/i',
            '/\bincandescent candle bulbs?\b/i',
            '/\bgreen.tinted incandescent\b/i',
            // Ceiling track/spot lighting (lighting fixture = EEE)
            '/\bceiling track spotlight\b/i',
            '/\bceiling.mounted (spot|multi.head) (track )?(light|spot|lighting)\b/i',
            '/\bceiling track.*lighting\b/i',
            // Floor-level LED plinth/spot lights
            '/\bfloor.level led (plinth|spot|recessed) lights?\b/i',
            // Computer mouse
            '/\bcomputer mouse\b/i',
            // Camera (when the camera IS the component/item)
            '/\bcamera\b(?! (lens|bag|case|mount|bracket|strap|housing))/i',
            // Intel processor stickers (implies computer = primary EEE)
            '/\bintel (core|pentium|sticker|processor|branded)\b/i',
            '/\binternal processor\b/i',
            // Electric hob (generic)
            '/\bhob\b(?!.*(gas|burner|ignition|petrol|flame))/i',
            // Interactive toy buttons/panels (battery-powered electronic toys)
            '/interactive (button|panel|piano|keyboard|play|activity|sound)\b/i',
            '/\binteractive light.up buttons?\b/i',
            '/\belectronic (learning toy|sound module|monitor unit|toy activity)\b/i',
            '/\belectronic sound and light\b/i',
            // Internal primary components (inferred by model)
            '/\binternal amplifier\b/i',
            '/\binternal backlight\b/i',
            '/\binternal belt drive mechanism\b/i',
            '/\binternal blade assembly\b/i',
            '/\binternal (cd|dvd|optical|disc) (mechanism|drive|laser|playback)\b/i',
            '/\binternal centrifugal (juicing|blending) mechanism\b/i',
            '/\binternal (computer|computing) (hardware|components|module)\b/i',
            '/\binternal (condenser|heat pump) unit\b/i',
            '/\binternal cooling fan\b/i',
            '/\binternal (cd laser|laser pickup)\b/i',
            // Wall-mounted electrical outlets (EEE installation)
            '/\bdouble mains socket\b/i',
            '/\bwall.mounted.*mains (socket|outlet)\b/i',
            // Evaporator coil (refrigeration primary)
            '/\bevaporator coil\b/i',
            // Water inlet valve (solenoid = motor/actuator)
            '/\bwater inlet valve\b/i',
            // Indoor TV aerial/antenna
            '/\bindoor (tv aerial|flat tv antenna|tv antenna)\b/i',
            '/\btv aerial\b/i',
            // Integrated lighting in aquarium hood / cabinet
            '/\bintegrated lighting unit\b/i',
            '/\bintegrated lid lighting\b/i',
            // Aquarium light
            '/\baquarium light\b/i',
            // "internal X (inferred from appliance type)" — broad catch for model-inferred components
            // Excludes combustion engines and 12v/lighting (those are non-EEE or supplementary)
            '/\binternal (?!combustion|12v|lighting)(.*)\(inferred from (appliance|caravan|device|product|toy|component) type[^)]*\)/i',
            // Internal primary components (verbose model phrasings without inferred suffix)
            '/\binternal (audio|amplifier|crossover) (dac|circuitry|circuit|amplifier)?\b/i',
            '/\binternal (lcd|led|display) (backlight|screen|panel|electronics|display)?\b/i',
            '/\binternal (cpu|motherboard|processor|logic board)\b/i',
            '/\binternal (cooling fan|cooling system|refrigeration|condenser\/heat pump)\b/i',
            '/\binternal (juicing|cutting|processing|blade|fuser|laser optical pickup)\b/i',
            '/\binternal (digital processing|media playback|microwave|filtration pump|magnetic resistance)\b/i',
            '/\binternal (hard drive|hdd)\b/i',
            '/\binternal electronics\b/i',
            '/\binternal electronic circuitry\b/i',
            // Flat panel / flat screen TV/monitor phrasings
            '/\bflat panel (lcd |)(monitor|display|screen)\b/i',
            '/\bflat.screen (television|tv|monitor)\b/i',
            '/\bflat.panel (monitor|display)\b/i',
            // Disc drives (all remaining optical drive phrasings)
            '/\boptical disc drive\b/i',
            '/\bdisc (drive|mechanism|loading mechanism|tray|tray slot|slot)\b(?!.*cassette|\bjam)/i',
            '/\bdisc loading (mechanism|slot)\b/i',
            '/\bdisc tray\b/i',
            '/\bdvd\+?r?w (optical|disc) drive\b/i',
            '/\bdvd(\/disc|±rw|\+rw|-rw) (drive|mechanism|optical)\b/i',
            '/\bdvd optical drive\b/i',
            '/\bcd\/disc drive\b/i',
            // Exterior lighting (caravan, building-mounted)
            '/\bexterior (light|lights|light fitting|light cluster|light fixture|light fittings|marker lights)\b/i',
            '/\bexternal wall light fitting\b/i',
            '/\bexterior roof.mounted.*light\b/i',
            // Hairdryer / electric lamp (primary EEE items)
            '/\bhairdryer\b/i',
            '/\belectric (lamp|heating base unit)\b/i',
            '/\billuminated bowl.shaped.*shade\b/i',
            // Gas/electric refrigerator (caravan — refrigerator is primary EEE)
            '/gas\/electric refrigerator\b/i',
            // Front mounted bike lights
            '/\bfront (light|mounted light|light\/bell) unit\b/i',
            // Display consoles (exercise bike computers etc)
            '/\bdisplay console\b/i',
            '/\bbattery.powered or (mains|usb) (computer|console|unit)\b/i',
            // Halogen bulbs (fix pattern to allow words between type and "bulbs")
            '/\bhalogen (spotlight|reflector|linear|capsule|candle|linear tube).*bulbs?\b/i',
            '/\bhalogen reflector spotlight\b/i',
            // Electric drill / power tool (inferred)
            '/\belectric (drill|power tool)\b/i',
            // Buttons with electronic/sound functions (toy/appliance buttons = supplementary)
            '/\bbuttons for sound effects\b/i',
            // Ceiling track spotlights (plural fix)
            '/\bceiling track spotlights?\b/i',
            '/\bceiling track spotlight bar\b/i',
            '/\bceiling.mounted (spot|multi.head) (track )?(light|spot|lighting)s?\b/i',
            // Floor-level recessed LED (fix ordering)
            '/\bfloor.level (recessed led|led recessed) spot ?lights?\b/i',
            '/\bfloor.level recessed led\b/i',
            '/\bled floor plinth lights?\b/i',
            '/\bfloor.level led plinth\b/i',
            // Illuminated disco/mirror ball (fix slash)
            '/\billuminated disco.*ball\b/i',
            '/\biridescent.*disco ball\b/i',
            // DVD player units (separate items visible in shot)
            '/\bdvd (or|\/vcr) (player|video player)\b/i',
            '/\bdvd\/vcr player unit\b/i',
            // LED lighting fixtures and strips (remaining variants)
            '/\bled (gu10|mr16|gu5\.3) (spotlight|downlight)?\b/i',
            '/\bled gu10 downlight\b/i',
            '/\bled lightbulb\b/i',
            '/\bled rope light\b/i',
            '/\bled spotlights?\b/i',
            '/\bled under.cabinet lighting\b/i',
            '/\bled mood lighting\b/i',
            '/\blight.up (activity|interactive|number|piano) (buttons?|panels?|keys?)\b/i',
            '/\blight.up buttons? and interactive panels?\b/i',
            '/\blight.up number buttons?\b/i',
            '/\blightbulb\b/i',
            '/\blinear halogen tube bulbs?\b/i',
            '/\bmulticoloured bulbs?\b/i',
            '/\bcandle bulbs?\b/i',
            // LED fire effects (remaining)
            '/\bled\/illuminated ember effect\b/i',
            '/\bled\/light effect simulating\b/i',
            '/\bdecorative flame.?effect bulbs?\b/i',
            // LED/LCD backlit panel
            '/\bled\/lcd backlit panel\b/i',
            '/\bled backlighting\b/i',
            // Loudspeaker drivers (loudspeaker = speaker)
            '/\bloudspeaker drivers?\b/i',
            '/\bmid\/bass driver\b/i',
            // Mains-powered appliances (the phrase IS the primary description)
            '/\bmains.powered (dishwasher|drum|hi.fi|refrigeration|satellite receiver|television|formula maker|multifunction|pvr|dvr)\b/i',
            '/\bmains power(ed)? (lamp|television|tv)\b/i',
            // Microwave oven (explicit)
            '/\bmicrowave oven\b/i',
            // Motherboard (explicit, as standalone string)
            '/\bmotherboard\b(?!.*visible through side window)/i',
            // Monitor/computer unit (exercise bike display)
            '/\bmonitor\/computer unit\b/i',
            '/\bdisplay console\/computer\b/i',
            // Mouse (computer mouse = EEE)
            '/\bmouse\b(?!.*pad|.*trap)/i',
            // Keyboard (generic = EEE)
            '/\bkeyboard\b(?!.*backlight|.*indicator|.*backlit|.*backlighting)/i',
            // iPod/MP3 docking cradle (primary EEE device)
            '/\bipod.*docking cradle\b/i',
            '/\bmp3 player dock\b/i',
            // Lower oven (second oven compartment = primary)
            '/\blower oven\b/i',
            // Battery-powered sound modules
            '/\bbattery.powered sound\b/i',
            '/\bsound module\b/i',
            // Piano/music light-up keys (electronic toy instruments)
            '/\bpiano.style light.up keys\b/i',
            '/\bcoloured light.up piano\b/i',
            // DVD or VCR player visible as separate unit in shot
            '/\bdvd or vcr player\b/i',
            // Electronic toy interactive buttons/panels
            '/\binteractive buttons\b/i',
            '/\binteractive toy panel\b/i',
            // Front-mounted light/bell unit (bike)
            '/\bfront mounted light\/bell unit\b/i',
            // Keyboard with backlight capability (the keyboard IS primary EEE)
            '/\bkeyboard with back(lit|light)\b/i',
            // LCD monitor (generic phrase)
            '/\blcd monitor\b/i',
            // LED MR16/GU5.3 spotlight bulbs
            '/\bled mr16\b/i',
            // Mains-powered internal circuitry / TV unit phrases
            '/\bmains.powered (internal circuitry|tv unit)\b/i',
            // Multiple strings of fairy/christmas lights
            '/\bstrings of (fairy|christmas) lights\b/i',
            // Optical drive (plain phrase — more specific variants already above)
            '/\boptical drive\b/i',
            // Overhead light fittings and strip lighting
            '/\boverhead light (fitting|hood|unit)\b/i',
            '/\boverhead strip lighting\b/i',
            // Oven (electric oven as standalone component string)
            '/^oven$/',
            '/\boven \(inferred from appliance type\)\b/i',
            // Parent unit (baby monitor)
            '/\bparent unit (monitor|receiver)\b/i',
            // Lighting unit integrated into lid
            '/\blighting unit integrated into lid\b/i',
            // Plug-in timer switch in wall socket (EEE device)
            '/\bplug.in timer switch\b/i',
            // Rear lights (vehicle tail/rear lights)
            '/\brear light\b/i',
            // Receiver/transmitter units
            '/\breceiver unit\b/i',
            '/\btransmitter unit\b/i',
            // Reflector bulbs (R-type, spotlight)
            '/\breflector (bulb|spotlight bulb|r.type bulb)\b/i',
            '/\breflector\/spotlight bulb\b/i',
            // Resistance mechanism console (exercise machine display)
            '/\bresistance (mechanism )?console\b/i',
            // SCART to HDMI converter (standalone EEE adapter device)
            '/\bscart.to.?hdmi\b/i',
            // Sensor mat/pad/unit (electronic sensor devices)
            '/\bsensor (mat|pad|unit)\b/i',
            // Small bayonet/screw cap / candle bulbs
            '/\bbayonet\/screw cap.*bulbs?\b/i',
            '/\bsmall candle\/(golf ball|ses base).*bulbs?\b/i',
            '/\bstandard bayonet.*bulb\b/i',
            // Small display/console units (exercise machine computers)
            '/\bsmall console\/display unit\b/i',
            '/\bsmall display\/(computer|console|led|monitor)\b/i',
            // Speakers (exact standalone strings; grille/cable variants handled below)
            '/^speaker$/',
            '/^speakers$/',
            '/\bpair of powered speakers\b/i',
            '/\btwo (passive )?bookshelf speakers\b/i',
            '/\btweeter drivers?\b/i',
            '/\bsubwoofer\b/i',
            // Spotlight lamp heads
            '/\bspotlight lamp head\b/i',
            '/\bsecondary lamp head\b/i',
            '/\bsecondary spotlight.*head\b/i',
            '/\btwin spotlight heads?\b/i',
            // Stainless steel extractor hood (mains-powered)
            '/\bstainless steel.*extractor hood\b/i',
            // Streaming device
            '/\bstreaming device\b/i',
            // Submersible pump
            '/\bsubmersible pump\b/i',
            // Table lamp (primary light source)
            '/\btable lamp\b/i',
            // Thin-bezel LED/LCD television panel
            '/\bthin.bezel led\/lcd\b/i',
            // Tonearm (record player component = primary)
            '/\btonearm\b/i',
            // Turntable platter / record player on stand
            '/\bturntable platter\b/i',
            '/\bturntable \(record player\)\b/i',
            // Uplight bowl lamp fitting
            '/\buplight bowl lamp fitting\b/i',
            // Voltage converter/transformer (slash-separated phrase variant)
            '/\bvoltage converter\/transformer unit\b/i',
            // Wall-mounted light fitting
            '/\bwall.mounted light fitting\b/i',
            // Water dispenser (plain phrase without trailing "unit")
            '/\bwater dispenser\b/i',
            // White rectangular battery/LED base units (toy/novelty items)
            '/\bwhite rectangular battery\/power\b/i',
            // Controller or junction box (small electronic control device)
            '/\bcontroller or junction box\b/i',
            // IR remote controls (plural variant)
            '/\bir remote controls\b/i',
            // TV (exact standalone string)
            '/^tv$/',
            // Small circular device (possibly speaker or smart device)
            '/\bsmall circular device.*possibly a speaker\b/i',
            // Electronic buttons (non-interactive prefix variant)
            '/\belectronic buttons\b/i',
            // Interactive panels (plural fix — existing pattern only has singular)
            '/\binteractive panels?\b/i',
            '/\bmusical.*interactive panels?\b/i',
            // Power tool (inferred from appliance type)
            '/\bpower tool\b/i',
            // Oven (inferred from appliance type) — trailing ) is non-word so no \b
            '/\boven \(inferred from appliance type\)/i',
            // Overhead light/hood unit (slash variant)
            '/\boverhead light[\/ ](fitting|hood|unit)\b/i',
            // Rear red light (battery-powered) — "red" between "rear" and "light"
            '/\brear red light\b/i',
            // Turntable (record player) — trailing ) not a word boundary
            '/\bturntable \(record player\)/i',
            // Multiple strings of fairy/christmas lights (slash-separated)
            '/\bchristmas lights\b/i',
            '/\bfairy lights\b/i',
        ],
        'supplementary_eee' => [
            // Display / indicators
            '/digital (display|clock|timer)/',
            '/\bdisplay panel\b/',
            '/\belectronic display\b/',
            '/\bindicator panel\b/',
            '/digital.*panel/',
            '/\bdigital console\b/',
            '/\btemperature control electronic/',
            // Switches / on-off controls
            '/\bon\/off switch\b/',
            '/\bpower switch\b/',
            '/\bpower.*control\b/',
            '/\bswitch.*trigger mechanism\b/',
            '/\bcoiled cable\b/',
            // Ignition
            '/electronic ignition/',
            '/electric ignition/',
            // Lights (not primary function)
            '/indicator light/',
            '/\bled (light|lighting|lights|accent|string|fairy)\b/',
            '/\bled\/fairy\b/',
            '/\bfairy light/',
            '/\bstring light/',
            '/\bpre.installed led\b/',
            '/\bpre.lit\b/',
            '/multicolour led/',
            '/interior (light|lighting|led)/',
            '/interior cavity light/',
            '/internal lighting/',
            '/\bneedle.*light/',
            '/\bsewing light\b/',
            '/\bbuilt.in.*light(ing)?\b/',
            '/\bhood.*light\b/',
            '/\blid.*light\b/',
            '/oven.*light/',
            '/\blight unit\b/',
            // Clocks/timers
            '/\bdigital clock\b/',
            '/\bdigital timer\b/',
            '/\btimer dial\b/',
            '/\bclock module\b/',
            '/\boven timer\b/',
            // Control interfaces
            '/control (button|panel|keypad)/',
            '/push.?button/',
            '/\bstart and stop button\b/',
            '/\bstart.*stop.*button/',
            '/programme selector/',
            '/\bmembrane.*keypad\b/',
            '/\bstitch selector\b/',
            // Sensors
            '/\bpulse sensor/',
            '/\bheart rate sensor/',
            '/handlebar.*sensor/',
            '/handlebar.*display/',
            // Electronic controls (not primary function)
            '/\belectronic (control|resistance)\b/',
            '/\belectronic resistance control\b/',
            // Foot pedals (supplementary speed control)
            '/\bfixed.?pedal\b/',
            '/\bfoot pedal\b/',
            // Mains power connections (confirm electrical, not primary function)
            '/\bmains power (flex|cable|cord|connection|plug|adapter)/',
            '/\bmains power flex\b/',
            '/mains.?powered appliance/',
            '/\belectrical cable/',
            '/\bpower cable/',
            '/\bpower flex\b/',
            '/\bmains hardwire\b/',
            '/\bmains appliance\b/',
            '/mains\/power connection/',
            '/\bplug socket\b/',
            '/\buk (3-pin|plug)\b/',
            // Safety switches/guards
            '/\bsafety (switch|guard|button|key)\b/',
            '/\block.off switch\b/',
            '/\bmagnetic safety key\b/',
            // Audio I/O connectors (confirm electrical connection, not primary function)
            '/\b(3\.5mm|audio) (jack|port|cable|connector|socket|input|output)\b/i',
            '/\baudio jacks?\b/i',
            '/\b(rca|phono) (connector|socket|cable|output|audio)\b/i',
            '/composite\/rca/i',
            '/\bs.video (port|connector|output)\b/i',
            '/\baudio (output|input) (socket|port|sockets)\b/i',
            '/\bleft\/right channel connector\b/i',
            '/\bbinding posts?\b/i',
            // Video/data connectors
            '/\bscart (port|socket|cable|output|plug)\b/i',
            '/\bcoaxial (cable|connector|plug|socket|aerial)\b/i',
            '/\bsatellite dish input connector\b/i',
            '/\b(dvi|vga|sata|displayport).{0,20}(port|connector|cable|socket)\b/i',
            '/\bsignal cable\b/i',
            '/\brf connector\b/i',
            '/\bferrite choke\b/i',
            // Charging
            '/\bcharging (cable|port|dock|adapter|connector)\b/i',
            '/\bcharging cable\/adapter\b/i',
            '/\bcharging cable\/power adapter\b/i',
            // Cordless/swivel kettle bases (power connector, not primary function)
            '/360.degree.*base/i',
            '/360.*swivel.*base/i',
            '/360.*power.*base/i',
            '/360.*rotary.*base/i',
            '/\bcordless (swivel |rotary |power |)base\b/i',
            // Lamp holders/sockets (electrical fitting, but light-emitting element is primary)
            '/\blamp (holder|bulb holder|bulb socket|socket)(\b|\/)/i',
            '/\bbulb (holder|socket|fitting)\b/i',
            '/\bbulb holder\/socket\b/i',
            '/\bcandle.style lamp (holder|socket)\b/i',
            // Power adapters / PSUs (not primary function — connect power)
            '/\bpower (adapter|supply unit|supply)\b/i',
            '/\bblack power adapter\b/i',
            '/\bcharging.*power adapter\b/i',
            // Speaker cables / terminals
            '/\bspeaker (cable|wire|lead)s?\b/i',
            '/\bspeaker input (terminal|connector|post)s?\b/i',
            '/\bspeaker cable(s|\/wire)\b/i',
            '/\bspeaker terminals?\b/i',
            // General connecting cables (confirm electrical, not primary function)
            '/\bconnecting cables?\b/i',
            '/\bcable\/lead\b/i',
            '/\baudio cable\b/i',
            '/\bcables and (connecting |)wires\b/i',
            '/\bconnecting cables\/wiring\b/i',
            // Backlit keyboard (supplementary feature of a laptop/PC)
            '/\bbacklit keyboard\b/i',
            '/\bbuilt.in keyboard\b/i',
            // Card slots/readers (I/O, not primary)
            '/\b(sd card|memory card|card) (slot|reader)\b/i',
            '/\bcard slot (or|on) (front|media|similar|reader)\b/i',
            // AV/data ports
            '/\b(hdmi|ethernet|usb) (port|socket|connector|cable|input|output)\b/i',
            '/\bac in (mains|power) input port\b/i',
            '/\baudio in port\b/i',
            // Infrared emitter (supplementary remote control function)
            '/\binfrared emitter\b/i',
            '/\bbuilt.in infrared\b/i',
            // Charging port for devices
            '/\bcharging port (inferred|visible)\b/i',
            // USB ports / cables (I/O connections, not primary function)
            '/\busb ports?\b/i',
            '/\busb (cable|device|port|connector|power connector)\b/i',
            '/\busb\/optical drive bays\b/i',
            // Wifi indicators
            '/\bwifi indicator\b/i',
            // Power indicator LEDs / standby lights
            '/\b(blue|red|green|amber) (power|standby|indicator) (led|light|button)\b/i',
            '/\bpower indicator led\b/i',
            '/\bstandby.*indicator led\b/i',
            '/\bindicator (led|light|button|lamp)\b/i',
            // Ambient / accent lighting (supplementary decorative feature)
            '/\bambient light (feature|strip)\b/i',
            // Fluorescent tube connectors
            '/\bbi.pin fluorescent tube connector\b/i',
            '/\bfluorescent tube connector\b/i',
            // Mains plug adapters / chargers
            '/\buk mains plug (adapter|charger)\b/i',
            '/\bmains plug\/charger\b/i',
            // Wall mounting with wiring
            '/\bwall mounting backplate with internal wiring\b/i',
            '/\bwall plate with wiring\b/i',
            // Viewing windows with backlighting (supplementary feature)
            '/viewing window.*(backlit|internal light)\b/i',
            // Video input ports/cables
            '/\bvideo (input|signal input) (port|ports|cable)\b/i',
            '/\bvideo\/data cable\b/i',
            // Speaker grilles (supplementary — implies speakers present)
            '/\bbuilt.in.*speaker grilles?\b/i',
            // 3.5mm headphone jacks (all variants)
            '/headphone jack\b/i',
            '/\bheadphone.*jack (socket|cable|connection)\b/i',
            // AC power input ports
            '/\bac in\b/i',
            '/\bdc power input\b/i',
            // Gas hob ignition (electrical ignition = supplementary on gas appliance)
            '/gas.*(ignition|hob ignition|spark ignit)/i',
            '/\bignition (spark|system)\b(?!.*(electric drill|power tool))/i',
            // Infrared receivers (supplementary remote control function)
            '/\binfrared receiver\b/i',
            '/\binfrared transmitter\b/i',
            '/\binfra.?red (receiver|transmitter|window)\b/i',
            // Built-in trackpad (input component, laptop is primary)
            '/\bbuilt.in trackpad\b/i',
            '/\bintegrated touchpad\b/i',
            // Built-in lights (accent/indicator, not primary function)
            '/\bbuilt.in lights?\b/i',
            // Dimmer/switch on lamp stem or cable
            '/\bdimmer.*switch.*stem\b/i',
            '/\binline (switch|dimmer|controller)\b/i',
            // E27/E14/G9 lamp socket bases (connector fitting)
            '/\be27 screw base\b/i',
            '/\be27.*mains connector\b/i',
            // FM/antenna inputs
            '/\bfm antenna (input|socket|coaxial)\b/i',
            '/\bfm antenna\b/i',
            '/\bcoaxial.*antenna input\b/i',
            // Front panel displays / controls
            '/\bfront panel (display|button|control|indicator|navigation|readout|power button)\b/i',
            // Exterior 12v/mains hook-up (caravan power connection)
            '/\bexterior.*hook.up socket\b/i',
            '/\bexterior 12v\b/i',
            '/\bexterior (240v|mains) (hook.up|socket)\b/i',
            // Internal control/wiring (confirms electrical, not primary function)
            '/\binternal control (board|circuitry|electronics)\b/i',
            '/\binternal (cabling|wiring)\b/i',
            '/\binternal cables?\b/i',
            '/\binternal 12v.*lighting\b/i',
            '/\binternal 12v.*hook.up\b/i',
            // Interior lighting (supplementary feature inside fridge/cabinet)
            '/\binterior (cabinet|refrigerator|display) (lighting|light)\b/i',
            '/\binterior (cabinet lighting|refrigerator light)\b/i',
            // Ceiling rose / wall bracket with wiring connection
            '/\bceiling rose\b/i',
            '/\bwall bracket.*electrical mounting\b/i',
            '/\bchrome.finished wall bracket.*electrical\b/i',
            // Hood/canopy lighting
            '/\bhood.*canopy.*lighting unit\b/i',
            '/\bhood\/canopy lighting\b/i',
            // HDMI/AV ports and cables
            '/\bhdmi (or|and).*(cable|port|input|adapter)\b/i',
            '/\bhdmi.*port.*bezel\b/i',
            '/\bhdmi or (av|data|esata) (cable|port)\b/i',
            // Heat/humidistat controls (supplementary controls)
            '/\bheat selector switch\b/i',
            '/\bhumidistat control\b/i',
            // Indicator LEDs (non-primary indicator lights)
            '/\bindicator leds?\b(?!.*primary)/i',
            '/\bindicator\/power button\b/i',
            '/\bindicator\/status lights?\b/i',
            // Bulb sockets (lamp fittings = supplementary)
            '/\bindividual replaceable bulb socket\b/i',
            '/\bcandle.style lamp holder\b/i',
            '/\bbulb sockets\/lamp holders\b/i',
            // Green wiring / data cables (supplementary)
            '/\bgreen (cable|insulated cable|insulated wire)\b/i',
            '/\bdata cable\b/i',
            '/\binterconnecting cables\b/i',
            '/\belectrical wiring visible\b/i',
            '/\bcable (running|\/wire connecting|\/wire visible)\b/i',
            '/\bclear wire cable\b/i',
            // Ethernet/LAN (supplementary data I/O)
            '/\bethernet.*lan port\b/i',
            '/\blan port\b/i',
            // Function buttons (supplementary controls)
            '/\bfunction selector buttons\b/i',
            '/\bcancel.defrost.reheat.*(button|function)\b/i',
            '/\bcontrol (knobs|switches).*ignition\b/i',
            '/\bcontrol knobs with electronic\b/i',
            '/\bcontrol switches.*buttons\b/i',
            '/\bbuttons and switches\b/i',
            // Eject buttons (supplementary mechanism control)
            '/\beject button\b/i',
            // Intel sticker variant
            '/\bintel.branded processor\b/i',
            // DTS audio system logo (confirms amplifier/audio processing present)
            '/\bdts audio system\b/i',
            // Inline connectors / switches
            '/\binline (connector|controller)\b/i',
            // Exterior cable/connector (caravan)
            '/\bexterior cable\b/i',
            // Door handle with controls (has controls = supplementary)
            '/\bdoor handle with integrated controls\b/i',
            // Mains plug / hook-up socket (confirm electrical)
            '/\bmains plug\b/i',
            '/\bmains hook.up socket\b/i',
            '/\bmains hook.up system\b/i',
            '/\bexternal 240v mains hook.up\b/i',
            '/\bmains power input\b/i',
            '/\bmains power port\b/i',
            '/\bmains power socket\b/i',
            '/\bmains ac adapter input\b/i',
            '/\bmains power connector port\b/i',
            '/\bmains power lead cable\b/i',
            '/\bmains power adaptor\b/i',
            '/\bmains power (implied|assumed|inferred|suggested|standard)\b/i',
            '/\bmains-powered (device|unit)\b/i',
            '/\bmains electrical supply\b/i',
            '/mains\/signal cables?\b/i',
            '/mains\/usb power input\b/i',
            // Infrared receivers (supplementary remote control function)
            '/\bir receiver (sensor|window|front panel)\b/i',
            // iPod/MP3 dock connector (supplementary I/O)
            '/\bipod.*dock connector\b/i',
            // Internal thermostat/control (supplementary temperature control)
            '/\binternal thermostat\b/i',
            // Internal light (supplementary interior lighting)
            '/\binternal (light|drum light|led\/light visible|led\.light visible)\b/i',
            // Mode buttons (supplementary function button)
            '/\bmode button\b/i',
            // Monitor/TV stand base (supplementary structural base with cables)
            '/\bmonitor.*signal cables?\b/i',
            '/\bmonitor power.*cables?\b/i',
            // Keyboard backlight (supplementary feature)
            '/\bkeyboard (backlight|backlighting)\b/i',
            // Metal base with electrical fitting (supplementary — base, not primary)
            '/\bmetal base with electrical fitting\b/i',
            // Mains floor lamp pole (supplementary — the stem, not the light source)
            '/\bmains floor lamp stem\b/i',
            '/\blamp shade with light fitting\b/i',
            '/\blamp\/light housing\b/i',
            // LED inferred from appliance type (supplementary — LED alone ≠ primary EEE)
            '/^led \(inferred from appliance type\)$/i',
            // LEDs and switches as standalone strings
            '/^leds$/i',
            '/^switches$/i',
            // On/off button/switch/auto/reverse variants
            '/\bon\/off\b/i',
            // Optical digital output (audio I/O port)
            '/\boptical digital output\b/i',
            // Orange indicator/marker lights
            '/\borange indicator\b/i',
            // Paper feed slot (printer component)
            '/\bpaper feed slot\b/i',
            // Internal cabinet light (possible or confirmed)
            '/\binternal cabinet light\b/i',
            // Power and signal cables
            '/\bpower and signal cables?\b/i',
            '/\bpower\/signal cables?\b/i',
            // Power brick or transformer in cable bundle
            '/\bpower brick or transformer\b/i',
            // Power button (all variants — catches "power button on front panel" etc.)
            '/\bpower buttons?\b/i',
            '/\bpower button\/(controls|indicator|switch)\b/i',
            '/\bpower controls\/switches\b/i',
            // Power cord / dial / indicator / selector / socket
            '/\bpower cord\b/i',
            '/\bpower dial\b/i',
            '/\bpower indicator\b/i',
            '/\bpower selector switch\b/i',
            '/\bpower socket\b/i',
            // Power/function, power/mode, power/on-off, power/reverse, power/speed
            '/\bpower\/(function|mode|on.off|reverse|speed) (buttons?|switch(es)?|controls?)\b/i',
            // Programme selection dial
            '/\bprogramme selection dial\b/i',
            // Radio antenna
            '/\bradio antenna\b/i',
            // RCA/composite ports (slash-reversed variant)
            '/\brca\/composite\b/i',
            // Rear red reflector/light (uncertain battery-powered status)
            '/\brear red (light\/reflector|reflector\/light)\b/i',
            // Remote sensor window
            '/\bremote sensor window\b/i',
            // Reset and set buttons
            '/\breset button\b/i',
            '/\bset button\b/i',
            // Resistance adjustment dial/mechanism
            '/\bresistance adjustment (dial|mechanism)\b/i',
            // RF/coaxial dish input connectors
            '/\brf\/coaxial dish input\b/i',
            // Satellite dish input connectors (plural)
            '/\bsatellite dish input connectors?\b/i',
            // Roof-mounted ventilation/skylight unit
            '/\broof.mounted ventilation\b/i',
            // Rotary controls that are supplementary (controls on EEE items)
            '/\brotary (power|programme|program|thermostat|timer|function|speed|spin) (dial|knob|switch|selector)\b/i',
            '/\brotary power (level |)dial\b/i',
            '/\brotary power\/function\b/i',
            '/\brotary function\/selector\b/i',
            '/\brotary timer\/prog/i',
            '/\brotary control knobs? with (electrical|indicator)\b/i',
            '/\brotary controls? on (rear|side)\b/i',
            '/\brotary\/slide control switch\b/i',
            '/\brotary speed selector\b/i',
            '/\brotary spin speed\b/i',
            // RS-232 serial port
            '/\brs.?232\b/i',
            // S-video/RCA composite (slash-separated variant)
            '/\bs.video\/rca\b/i',
            // SCART socket (not the SCART-to-HDMI converter which is primary)
            '/\bscart\b/i',
            // Sensor drying system
            '/\bsensor drying system\b/i',
            // Speaker input/output sockets and signal cables
            '/\bspeaker input\/output\b/i',
            '/\bspeaker\/signal cables?\b/i',
            // Speed controller/selector/incline controls
            '/\bspeed controller\b/i',
            '/\bspeed selector switch\b/i',
            '/\bspeed\/incline control electronics\b/i',
            // Standby indicators
            '/\bstandby (indicator|button)\b/i',
            '/\bstandby\/power indicator\b/i',
            // Start button / stitch selection panel
            '/\bstart button\b/i',
            '/\bstitch selection panel\b/i',
            // Suction power adjustment controls
            '/\bsuction power adjustment\b/i',
            // Temperature controls and dials
            '/\btemperature control (base|indicator|panel|dial)\b/i',
            '/\btemperature (dial|gauge)\b/i',
            '/\bthermostat\/control (indicator|unit)\b/i',
            '/\bthermostat\/temperature control\b/i',
            // Timer function display
            '/\btimer function display\b/i',
            // Top-mounted fan grille (physical cover with visible fan)
            '/\btop.mounted fan grille\b/i',
            // Touch panel control / touchpad / trackpad
            '/\btouch panel control\b/i',
            '/\btouchpad\b/i',
            '/\btrackpad\b/i',
            // Transparent wire cable
            '/\btransparent wire cable\b/i',
            // Trigger/switch mechanism on handle
            '/\btrigger\/switch mechanism\b/i',
            // TV antenna input (generic — "indoor tv antenna" already primary above)
            '/\btv antenna\b/i',
            // Two lamp bulb holders/sockets
            '/\btwo lamp (bulb holders?|bulb sockets?|holders?\/bulb sockets?)\b/i',
            // USB 3.0 ports (number variant not caught by plain "usb ports")
            '/\busb 3\.0 ports?\b/i',
            // USB cables (plural variant)
            '/\busb cables?\b/i',
            // USB/wireless connectivity
            '/\busb\/wireless connectivity\b/i',
            // White cable bundle / white insulated cable wiring
            '/\bwhite cable bundle\b/i',
            '/\bwhite insulated (cable|wire)\b/i',
            // Mains double socket / wall sockets (infrastructure)
            '/\bmains double socket\b/i',
            '/\bmains wall sockets?\b/i',
            '/\bmains power \(implied\b/i',
            '/\bmains power (base|lead|cable)\b/i',
            '/\bmains power lead\/cable\b/i',
            '/\bmains wiring connection point\b/i',
            '/\bmains wiring flex\b/i',
            '/\bmains wiring visible\b/i',
            // HDMI or AV/data cables
            '/\bhdmi or (av|data) cables?\b/i',
            // On-screen display menu (active display feature)
            '/\bon.screen display menu\b/i',
            // Black switch or sensor / small black device on shelf
            '/\bblack switch or sensor\b/i',
            '/\bsmall black device\/box\b/i',
            // Cable/wire connecting or visible in shot
            '/\bcable\/wire (connecting|visible)\b/i',
            // Candle-style lamp holders (multiple per sconce)
            '/\bcandle.style lamp holders\/bulb sockets\b/i',
            // Control knobs with yellow off indicators (plural fix)
            '/\bcontrol knobs? with yellow off indicators?\b/i',
            // Digital or analogue clock/timer display
            '/\bdigital or analogue clock\/timer display\b/i',
            // Floor head with brush roll selector switch
            '/\bfloor head with brush roll selector\b/i',
            // Front panel audio connectors and controls (not covered by existing pattern)
            '/\bfront panel (audio|headphone|microphone)\b/i',
            '/\bfront panel buttons\/(indicators|switches)\b/i',
            '/\bfront panel controls\/buttons\b/i',
            // Individual replaceable bulb sockets (plural)
            '/\bindividual replaceable bulb sockets\b/i',
            // Inline or base-mounted switch
            '/\binline or base.mounted switch\b/i',
            // Interactive buttons and activity switches (supplementary, not the full toy)
            '/\binteractive buttons and activity switches\b/i',
            // Internal evaporator/freezer shelf coils
            '/\binternal evaporator\/freezer shelf coils?\b/i',
            // Internal wash pump/spray arm assembly
            '/\binternal wash pump\b/i',
            // Internal window blinds suggesting interior electrics
            '/\binternal window blinds\b/i',
            // Lamp bulb holders/sockets (e14/g9 type fittings)
            '/\blamp bulb holders?\/sockets? \(e14\b/i',
            // LED/backlit control interface
            '/\bled\/backlit control interface\b/i',
            // Left/right channel connectors
            '/\bleft\/right channel connectors?\b/i',
            // Spark ignition for gas hobs (supplementary on gas appliance)
            '/\bspark ignition for gas\b/i',
        ],
        'non_electrical' => [
            '/\bdrain hose\b/',
            '/\bfilter\b(?! electronic)/',
            '/\bfabric\b/',
            '/\bdoor seal\b/',
            '/\bglass turntable\b/',
            '/\bturntable plate\b/',
            '/\bdetergent drawer\b/',
            '/\bdoor lock mechanism\b/',
            '/\bdoor interlock mechanism\b/',
            '/\bdoor (handle|hinge)\b(?!.*control)/',
            '/\bdoor with (mesh|microwave|glass)\b/',
            '/\bdoor handles? with gold/',
            '/\bintegrated door hinge/',
            '/\brotary (dial|knob)\b(?! (power|function))/',
            '/\banalog.*timer dial\b/',
            '/\bmechanical timer\b/',
            '/\brotary control knob\b/',
            '/\bcontrol knob(s)?\b(?! (with|electronic))/',
            '/\bwarning label\b/',
            '/\bsmart energy monitor\b/',
            '/\bsmall display.*non.functional\b/',
            // Lamp shades and fittings (non-electrical covers/holders)
            '/\blamp shade (support|carrier|gallery|ring)\b/i',
            '/\b(gallery|support) ring\b/i',
            '/\bshade ring\b/i',
            '/\badjustable (reading arm|lamp head)\b/i',
            // Speaker grille (non-electrical cloth/cover)
            '/\bspeaker grille\b/i',
            // Ventilation / air vents
            '/\bair vents?\b/i',
            '/\bventilation grille\b/i',
            '/\bcondenser fan grille\b/i',
            // Mechanical dials and knobs
            '/\bcontrol dials?\b(?!.*electronic)/i',
            '/\bbrowning control (dial|knob)\b/i',
            '/\banalogue (temperature gauge|dial)\b/i',
            '/\banalog(ue)? dial\b/i',
            // Cartridges (ink/toner — not themselves electrical)
            '/\bcartridge\b(?!.*electric)/i',
            // Mechanical lamp parts
            '/\badjustable (reading )?arm (lamp|with)\b/i',
            '/\blamp shade\b(?!.*light fitting)/i',
            '/\blamp shade carrier\b/i',
            '/\bdecorative.*shade\b/i',
            // Mechanical mechanisms
            '/\blever\/push.down mechanism\b/i',
            '/\b(tray|drawer) mechanism\b(?!.*motoris)/i',
            // Lamp shades (all variants)
            '/\bamber.*glass.*lamp shade\b/i',
            '/\buplight (bowl|cone) shade\b/i',
            '/\bglass (tulip.shaped|lamp) shade\b/i',
            // Mechanical arms and lamp fittings
            '/\badjustable reading lamp arm\b/i',
            '/\badjustable reading arm\b/i',
            // Vacuum cleaner accessories (non-electrical tubes/attachments)
            '/\bvacuum cleaner attachments?\b/i',
            '/\bwand\/tube assembly\b/i',
            // Viewing windows (mechanical transparent cover)
            '/\bviewing window\b(?!.*(backlit|led|light|illuminat))/i',
            // Volume/control knobs (mechanical)
            '/\bvolume knob\b/i',
            // Water level windows (transparent sight glass)
            '/\bwater level (indicator window|window|indicator)\b/i',
            // Lamp shade with bracket/fitting (not the electrical part)
            '/\bbulb (holder|socket) stem\b/i',
            // Caravan/RV non-electrical fittings
            '/\b12v\/mains internal wiring system\b/i',  // wiring only
            // General warning/spec labels
            '/\bwarning labels consistent with powered\b/i',
            // Camera lens (just the optical glass, not the camera)
            '/\bcamera lens\b/i',
            // Dust canisters (non-electrical vacuum collection chamber)
            '/\bdust canister\b/i',
            '/\bcyclone unit\b(?!.*motor)/i',
            '/\bdust collection chamber\b/i',
            // Energy rating labels (just a label, not a component)
            '/\benergy rating label\b/i',
            '/\benergy (rating|efficiency) label\b/i',
            '/\beu energy rating\b/i',
            // Extension wands / hoses (vacuum accessories)
            '/\bextension wand\b/i',
            '/\bflexible (corrugated|suction) hose\b/i',
            '/\bflexible suction hose attachment\b/i',
            '/\bwand\/tube assembly\b/i',
            '/\bwand.tube assembly\b/i',
            // Gooseneck arms (mechanical)
            '/\bflexible metal gooseneck arm\b/i',
            '/\bgooseneck arm\b/i',
            // Frosted diffuser panels (light cover, not electrical)
            '/\bfrosted plastic diffuser\b/i',
            '/\bfrosted.*diffuser panel\b/i',
            // Fuel tanks (petrol/gas = non-EEE)
            '/\bfuel tank\b/i',
            // Gas burners (combustion = non-EEE)
            '/\bgas burners?\b/i',
            '/\bgas hob burners?\b/i',
            '/\bgas stove burners?\b/i',
            '/\bgas oven\b/i',
            // Internal combustion engines (petrol/gas = non-EEE)
            '/\binternal combustion engine\b/i',
            // Lamp shade variants
            '/\bglass (shade|tulip.*shade)\b/i',
            '/\bamber.*glass.*shade\b/i',
            // Drum interiors (non-electrical structure)
            '/\bdrum interior\b/i',
            '/\bvented barrel\b/i',
            // Chrome rotary dials (browning controls on toasters)
            '/\bchrome rotary dials?\b/i',
            '/\bbrowning control\b/i',
            // Heating grille housing (external casing)
            '/\bheating grille.*housing\b/i',
            // Integrated sink (plumbing, not electrical)
            '/\bintegrated sink\b/i',
            // Central mounting bracket (structural, not electrical)
            '/\bcentral mounting bracket\b/i',
            // Amber glass lamp shades (plural)
            '/\bamber.*glass.*shades?\b/i',
            '/\bglass.*tulip.*shades?\b/i',
            // Lamp fittings/brackets (decorative metal, non-electrical)
            '/\blamp fitting\/bracket\b/i',
            '/\blight fitting bracket\b/i',
            '/\bbrass.*lamp fitting\b/i',
            '/\bbrass.*light fitting bracket\b/i',
            // Door seals and handles (purely mechanical/structural)
            '/\bdoor seals and handles\b/i',
            '/\bpassive components integral to appliance\b/i',
            // Bulb visible in spotlight head (just the bulb in the fitting)
            '/\bbulb visible in.*spotlight head\b/i',
            // Lower front grille (air intake = non-electrical)
            '/\blower front grille\b/i',
            '/\bair intake grille\b/i',
            // Monitor/TV stand base (structural base, non-electrical)
            '/\bmonitor\/tv stand base\b/i',
            '/\btv stand base\b/i',
            // Metal safety grille (protective cover)
            '/\bmetal safety grille\b/i',
            // Mesh-screened microwave door window (non-electrical window)
            '/\bmesh.screened.*door window\b/i',
            // Motherboard visible through window (the window/panel is non-electrical)
            '/\bmotherboard visible through side window\b/i',
            // Oven door glass panels / oven doors (physical structure, not the heating element)
            '/\boven door (glass panels?|viewing windows?)\b/i',
            '/^oven doors?$/',
            // Push-down lever mechanism (toaster spring lift)
            '/\bpush.down.*lever mechanism\b/i',
            // Rating plate/label (specification sticker, not a component)
            '/\brating plate\/label\b/i',
            // Second oven/grill compartment (physical partition in range cooker)
            '/\bsecond (oven|grill|oven\/grill) compartment\b/i',
            // Speaker dust cap (physical paper/cloth cone component)
            '/\bspeaker dust cap\b/i',
            // Stainless steel sink (plumbing fixture, not electrical)
            '/\bstainless steel sink\b/i',
            // Top air intake/exhaust grille (ventilation opening; slash-separated variant)
            '/\btop air (intake|exhaust|intake\/exhaust) grille\b/i',
            // Toast lift lever (mechanical spring mechanism in toaster)
            '/\btoast lift lever\b/i',
            // Transparent dust collection canister (vacuum bin)
            '/\btransparent dust collection canister\b/i',
            // Frosted glass/plastic lamp shades (decorative covers, not EEE)
            '/\btwo (white )?frosted glass\/plastic lamp shades?\b/i',
            // Upper oven with glass door (physical structure)
            '/\bupper oven with glass door\b/i',
            // Ring burners (gas cooking, not electric)
            '/\bwith (two )?ring burners?\b/i',
            // Dustbin/canister unit on handle (vacuum dust collector)
            '/\bsmall dustbin\/canister unit\b/i',
        ],
    ];

    public function __construct(protected EeeSqliteService $sqlite) {}

    /** Return true if the component index needs to be built (table is empty). */
    public function needsBuilding(): bool
    {
        $pdo  = $this->sqlite->getPdo();
        $row  = $pdo->query("SELECT COUNT(*) FROM eee_component_types")->fetchColumn();
        return (int) $row === 0;
    }

    /**
     * Build the component index from observed component strings in eee_classifications.
     * Idempotent: skips strings already in the index.
     */
    public function buildIndex(callable $progress = null): array
    {
        $rawStrings = $this->collectRawStrings();

        if (empty($rawStrings)) {
            return ['added' => 0, 'skipped' => 0, 'total' => 0];
        }

        $existing = $this->getExistingRawStrings();
        $toIndex  = array_values(array_diff($rawStrings, $existing));

        if (empty($toIndex)) {
            return ['added' => 0, 'skipped' => count($rawStrings), 'total' => count($rawStrings)];
        }

        $embeddings = $this->fetchEmbeddingsBatch($toIndex, $progress);
        $added      = 0;

        foreach ($toIndex as $i => $raw) {
            $embedding = $embeddings[$i] ?? null;
            $category  = $this->autoCategory($raw);

            $this->sqlite->upsertComponentType([
                'canonical_name' => $raw,
                'category'       => $category,
                'embedding'      => $embedding ? $this->packEmbedding($embedding) : null,
                'raw_strings'    => json_encode([$raw]),
            ]);

            $added++;
        }

        return [
            'added'   => $added,
            'skipped' => count($rawStrings) - count($toIndex),
            'total'   => count($rawStrings),
        ];
    }

    /**
     * Find the canonical component type for a raw string.
     * Returns ['canonical_name', 'category', 'similarity'] or null if no match.
     */
    public function lookup(string $rawComponent): ?array
    {
        $raw = strtolower(trim($rawComponent));
        if (empty($raw)) return null;

        // Exact match first.
        $exact = $this->sqlite->getComponentTypeByName($raw);
        if ($exact) {
            return ['canonical_name' => $exact['canonical_name'], 'category' => $exact['category'], 'similarity' => 1.0];
        }

        // Vector similarity search.
        $embedding = $this->fetchEmbedding($raw);
        if (!$embedding) return null;

        return $this->findNearest($embedding, self::SIMILARITY_THRESHOLD);
    }

    /**
     * Classify a set of raw component strings and return the aggregate EEE verdict.
     *
     * @param  string[]  $components  Raw component strings from the model
     * @return array{is_eee: bool|null, contains_eee_components: bool, categories: array, unmatched: array}
     */
    public function classifyComponents(array $components): array
    {
        $categories  = [];
        $unmatched   = [];
        $hasPrimary  = false;
        $hasSupp     = false;

        foreach ($components as $raw) {
            $match = $this->lookup($raw);
            if ($match) {
                $categories[$raw] = $match;
                if ($match['category'] === 'primary_eee')      $hasPrimary = true;
                if ($match['category'] === 'supplementary_eee') $hasSupp    = true;
            } else {
                $unmatched[] = $raw;
            }
        }

        return [
            'is_eee'                  => $hasPrimary ? true : ($hasSupp ? null : (empty($components) ? null : false)),
            'contains_eee_components' => $hasPrimary || $hasSupp,
            'categories'              => $categories,
            'unmatched'               => $unmatched,
        ];
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    protected function collectRawStrings(): array
    {
        $pdo  = $this->sqlite->getPdo();
        $rows = $pdo->query("
            SELECT DISTINCT LOWER(TRIM(part)) AS raw
            FROM (
                SELECT value AS part
                FROM eee_classifications, json_each(
                    '[\"' || REPLACE(electrical_components_description, ';', '\",\"') || '\"]'
                )
                WHERE electrical_components_description IS NOT NULL
                  AND electrical_components_description != ''
            )
            WHERE TRIM(part) != ''
        ")->fetchAll(\PDO::FETCH_COLUMN);

        return array_values(array_unique(array_filter($rows)));
    }

    protected function getExistingRawStrings(): array
    {
        $pdo = $this->sqlite->getPdo();
        return $pdo->query("SELECT LOWER(canonical_name) FROM eee_component_types")
                   ->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function autoCategory(string $raw): string
    {
        $lower = strtolower(trim($raw));
        foreach (self::CATEGORY_RULES as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $lower)) {
                    return $category;
                }
            }
        }
        return 'unknown';
    }

    /** Fetch a single embedding from Gemini text-embedding-004. */
    protected function fetchEmbedding(string $text): ?array
    {
        $results = $this->fetchEmbeddingsBatch([$text]);
        return $results[0] ?? null;
    }

    /** Batch-fetch embeddings via OpenAI text-embedding-3-small (max 2048 per call). */
    protected function fetchEmbeddingsBatch(array $texts, callable $progress = null): array
    {
        $apiKey  = config('freegle.eee.openai_api_key');
        $model   = self::EMBEDDING_MODEL;
        $url     = 'https://api.openai.com/v1/embeddings';
        $results = [];
        $chunks  = array_chunk($texts, 100, true);

        foreach ($chunks as $chunk) {
            try {
                $response = Http::timeout(60)
                    ->withToken($apiKey)
                    ->post($url, ['model' => $model, 'input' => array_values($chunk)]);

                if (!$response->successful()) {
                    Log::error('EeeComponentService embed failed', ['status' => $response->status(), 'body' => $response->body()]);
                    foreach (array_keys($chunk) as $origIdx) {
                        $results[$origIdx] = null;
                    }
                    continue;
                }

                $data = $response->json('data', []);
                foreach (array_keys($chunk) as $i => $origIdx) {
                    $results[$origIdx] = $data[$i]['embedding'] ?? null;
                }
            } catch (\Throwable $e) {
                Log::error('EeeComponentService embed exception', ['error' => $e->getMessage()]);
                foreach (array_keys($chunk) as $origIdx) {
                    $results[$origIdx] = null;
                }
            }

            if ($progress) {
                $progress(count($results), count($texts));
            }
        }

        return array_values($results);
    }

    protected function findNearest(array $queryEmbedding, float $threshold): ?array
    {
        $pdo  = $this->sqlite->getPdo();
        $rows = $pdo->query("SELECT canonical_name, category, embedding FROM eee_component_types WHERE embedding IS NOT NULL")
                    ->fetchAll(\PDO::FETCH_ASSOC);

        $best     = null;
        $bestSim  = -1.0;

        foreach ($rows as $row) {
            $vec = $this->unpackEmbedding($row['embedding']);
            if (!$vec) continue;
            $sim = $this->cosineSimilarity($queryEmbedding, $vec);
            if ($sim > $bestSim) {
                $bestSim = $sim;
                $best    = $row;
            }
        }

        if ($best === null || $bestSim < $threshold) return null;

        return [
            'canonical_name' => $best['canonical_name'],
            'category'       => $best['category'],
            'similarity'     => $bestSim,
        ];
    }

    protected function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot  += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }
        if ($magA === 0.0 || $magB === 0.0) return 0.0;
        return $dot / (sqrt($magA) * sqrt($magB));
    }

    protected function packEmbedding(array $floats): string
    {
        return pack('f*', ...$floats);
    }

    protected function unpackEmbedding(string $blob): ?array
    {
        if (empty($blob)) return null;
        $unpacked = unpack('f*', $blob);
        return $unpacked ? array_values($unpacked) : null;
    }
}
