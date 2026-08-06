<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class WhatJobsService
{
    // Minimum cost-per-click for a WhatJobs listing to be worth ingesting/showing.
    // Lowered 0.10 -> 0.08 on 2026-07-09: WhatJobs compressed ~75% of their bids to
    // ~£0.084 (just under the old £0.10 floor), collapsing our billable feed from
    // ~225k to ~80k and tripping the swap-guard so the jobs table went stale. £0.08
    // sits just below that £0.084 spike (≈10th percentile of the current feed),
    // recapturing the bulk (~325k) while still excluding the genuine sub-£0.08 tail.
    // Must match Job::MINIMUM_CPC (serving), Go job.JOBS_MINIMUM_CPC, and V1.
    const MINIMUM_CPC = 0.08;
    const MAX_AGE_DAYS = 7;
    const DISTRIBUTE = 0.0005;
    const BATCH_SIZE = 500;
    // Refuse to swap the live `jobs` table when parsed count is < this fraction
    // of the existing row count, provided the existing table has at least
    // SWAP_RATIO_MIN_EXISTING rows. Guards against a partial feed failure
    // (e.g. an HTTP 429 on the WhatJobs feed while the dead clickcast feed
    // returns 0) silently wiping the live table.
    const MIN_SWAP_RATIO = 0.5;
    const SWAP_RATIO_MIN_EXISTING = 1000;

    // Where the feed-change gate keeps what it knows about each feed (validators
    // and a content hash) between runs. One row in the existing config table, so
    // there is no schema step.
    const GATE_CONFIG_KEY = 'whatjobs.feed_state';

    // The gate may never skip for longer than this. Six syncs a day all deciding
    // "unchanged" would leave the jobs table - and the jobs.seenat freshness check
    // that watches it (ScheduledOutcomeRegistry, 24h floor) - untouched for a day,
    // and would turn a gate bug that wrongly reports "unchanged" into a silent
    // permanent stall. Forcing a real rebuild once inside the monitor's window means
    // the existing alarm still fires if anything here breaks.
    const MAX_SKIP_HOURS = 20;

    // UK bounding box for geocoder
    const UK_SWLAT = 49.959999905;
    const UK_SWLNG = -7.57216793459;
    const UK_NELAT = 58.6350001085;
    const UK_NELNG = 1.68153079591;

    // Special-case address normalisation (matches the legacy V1 PHP Jobs::geocode)
    private const ADDRESS_FIXES = [
        'West Marsh'      => 'Grimsby',
        'Stoney Middleton' => 'Stoney Middleton, Derbyshire',
        'Middleton Stoney' => 'Middleton Stoney, Oxfordshire',
        'Kirkwall'        => 'Kirkwall, Orkney',
        'City of York'    => 'York',
        'Sutton Central'  => 'Sutton, London',
        'Hampden Park'    => 'Hampden Park, Eastbourne',
    ];

    private const CANONICAL_JOBS = [
        'Accountant' => true, 'Account Manager' => true, 'Activities Coordinator' => true,
        'Administrator' => true, 'Architect' => true, 'Area Manager' => true,
        'Assistant Manager' => true, 'Bartender' => true, 'Bid Manager' => true,
        'Bookkeeper' => true, 'Branch Manager' => true, 'Bricklayer' => true,
        'Building Inspector' => true, 'Building Surveyor' => true, 'Bus Driver' => true,
        'Business Analyst' => true, 'Business Development Manager' => true, 'Buyer' => true,
        'CAD Technician' => true, 'Care Assistant' => true, 'Care Coordinator' => true,
        'Care Worker' => true, 'Carpenter' => true, 'Cashier' => true,
        'Catering Assistant' => true, 'Chef' => true, 'Cleaner' => true,
        'Clinical Assessor' => true, 'CNC Machinist' => true, 'Communications Engineer' => true,
        'Compliance Officer' => true, 'Construction Manager' => true, 'Contracts Manager' => true,
        'Cook' => true, 'Counsellor' => true, 'Credit Controller' => true,
        'Customer Service Advisor' => true, 'Data Analyst' => true, 'Data Architect' => true,
        'Data Engineer' => true, 'Delivery Driver' => true, 'Dental Nurse' => true,
        'Deputy Manager' => true, 'Design Engineer' => true, 'Design Manager' => true,
        'Digital Marketing Executive' => true, 'Document Controller' => true,
        'Door Canvasser' => true, 'Ecologist' => true, 'Electrical Engineer' => true,
        'Electrician' => true, 'Embedded Software Engineer' => true,
        'Engineering Apprentice' => true, 'Estimator' => true, 'Factory Operative' => true,
        'Female Support Worker' => true, 'Field Sales Representative' => true,
        'Field Service Engineer' => true, 'Finance Assistant' => true,
        'Finance Business Partner' => true, 'Finance Manager' => true,
        'Financial Controller' => true, 'Forklift Driver' => true, 'Fundraiser' => true,
        'Gas Engineer' => true, 'General Manager' => true, 'Groundworker' => true,
        'Head of Finance' => true, 'Head of Marketing' => true,
        'Healthcare Assistant' => true, 'HGV Class 1 Driver' => true,
        'HGV Class 2 Driver' => true, 'HGV Technician' => true, 'Home Manager' => true,
        'Housekeeper' => true, 'HR Advisor' => true, 'HR Business Partner' => true,
        'Installer' => true, 'IT Support' => true, 'IT Apprentice' => true,
        'Kitchen Assistant' => true, 'Kitchen Designer' => true, 'Labourer' => true,
        'Lecturer' => true, 'Legal Secretary' => true, 'Lifeguard' => true,
        'Machine Learning Engineer' => true, 'Machine Operator' => true,
        'Maintenance Electrician' => true, 'Maintenance Engineer' => true,
        'Maintenance Manager' => true, 'Maintenance Technician' => true,
        'Management Accountant' => true, 'Manufacturing Engineer' => true,
        'Marketing Manager' => true, 'Maths Teacher' => true,
        'Mechanical Design Engineer' => true, 'Mechanical Engineer' => true,
        'Mechanical Fitter' => true, 'Mechanic' => true, 'Mobile Tyre Fitter' => true,
        'Mortgage Advisor' => true, 'Multi Trade Operative' => true,
        'Nursery Manager' => true, 'Nursery Practitioner' => true, 'Nurse' => true,
        'Operations Manager' => true, 'Painter' => true, 'Parts Advisor' => true,
        'Passenger Assistant' => true, 'Payroll Administrator' => true,
        'Payroll Specialist' => true, 'Personal Advisor' => true, 'Planning Officer' => true,
        'Plasterer' => true, 'Plumber' => true, 'Primary Teacher' => true,
        'Production Manager' => true, 'Production Operative' => true,
        'Production Supervisor' => true, 'Project Engineer' => true, 'Project Manager' => true,
        'Property Manager' => true, 'Quality Engineer' => true, 'Quality Inspector' => true,
        'Quality Manager' => true, 'Quantity Surveyor' => true, 'Reach Truck Driver' => true,
        'Receptionist' => true, 'Recruitment Consultant' => true,
        'Refrigeration Engineer' => true, 'Regional Sales Manager' => true,
        'Registered Manager' => true, 'Research Associate' => true,
        'Residential Support Worker' => true, 'Restaurant Team Member' => true,
        'Roofer' => true, 'Rough Sleeping Outreach Worker' => true,
        'Sales Administrator' => true, 'Sales Advisor' => true, 'Sales Consultant' => true,
        'Sales Engineer' => true, 'Sales Executive' => true, 'Sales Manager' => true,
        'Sales Representative' => true, 'Scaffolder' => true, 'School Crossing Patrol' => true,
        'Science Teacher' => true, 'Security Officer' => true, 'SEN Teacher' => true,
        'Senior Care Assistant' => true, 'Service Advisor' => true, 'Service Engineer' => true,
        'Service Manager' => true, 'Shift Engineer' => true, 'Shift Leader' => true,
        'Site Manager' => true, 'Social Worker' => true, 'Software Engineer' => true,
        'Solution Architect' => true, 'Store Manager' => true, 'Structural Engineer' => true,
        'Supervisor' => true, 'Supply Teacher' => true, 'Support Worker' => true,
        'Teaching Assistant' => true, 'Team Leader' => true, 'Technical Author' => true,
        'Tiler' => true, 'Transport Manager' => true, 'Transport Planner' => true,
        'Van Driver' => true, 'Vehicle Technician' => true, 'Warehouse Operative' => true,
        'Welder' => true, 'Window Installer' => true, 'Workshop Controller' => true,
        'Workshop Technician' => true,
    ];

    private const JOB_KEYWORD_MAP = [
        [['hgv', 'class 1'], 'HGV Class 1 Driver'],
        [['hgv', 'class 2'], 'HGV Class 2 Driver'],
        [['class 1'], 'HGV Class 1 Driver'],
        [['class 2'], 'HGV Class 2 Driver'],
        [['7.5t'], 'Delivery Driver'],
        [['7.5 tonne'], 'Delivery Driver'],
        [['delivery driver'], 'Delivery Driver'],
        [['hgv driver'], 'HGV Class 1 Driver'],
        [['hgv technician'], 'HGV Technician'],
        [['van driver'], 'Van Driver'],
        [['bus driver'], 'Bus Driver'],
        [['trade plate driver'], 'Van Driver'],
        [['forklift'], 'Forklift Driver'],
        [['flt driver'], 'Forklift Driver'],
        [['reach truck'], 'Reach Truck Driver'],
        [['driver rep'], 'Delivery Driver'],
        [['embedded software'], 'Embedded Software Engineer'],
        [['machine learning'], 'Machine Learning Engineer'],
        [['software engineer'], 'Software Engineer'],
        [['solution architect'], 'Solution Architect'],
        [['data architect'], 'Data Architect'],
        [['data engineer'], 'Data Engineer'],
        [['data analyst'], 'Data Analyst'],
        [['electrical design'], 'Electrical Engineer'],
        [['mechanical design'], 'Mechanical Design Engineer'],
        [['structural engineer'], 'Structural Engineer'],
        [['electrical engineer'], 'Electrical Engineer'],
        [['mechanical engineer'], 'Mechanical Engineer'],
        [['communications engineer'], 'Communications Engineer'],
        [['refrigeration engineer'], 'Refrigeration Engineer'],
        [['field service engineer'], 'Field Service Engineer'],
        [['service engineer'], 'Service Engineer'],
        [['shift engineer'], 'Shift Engineer'],
        [['gas engineer'], 'Gas Engineer'],
        [['project engineer'], 'Project Engineer'],
        [['design engineer'], 'Design Engineer'],
        [['manufacturing engineer'], 'Manufacturing Engineer'],
        [['quality engineer'], 'Quality Engineer'],
        [['sales engineer'], 'Sales Engineer'],
        [['automation engineer'], 'Mechanical Engineer'],
        [['battery', 'engineer'], 'Electrical Engineer'],
        [['multi skilled', 'engineer'], 'Maintenance Engineer'],
        [['multi-skilled', 'engineer'], 'Maintenance Engineer'],
        [['maintenance engineer'], 'Maintenance Engineer'],
        [['maintenance technician'], 'Maintenance Technician'],
        [['maintenance electrician'], 'Maintenance Electrician'],
        [['shift technician'], 'Maintenance Technician'],
        [['electrical technician'], 'Electrical Engineer'],
        [['mechanical technician'], 'Mechanical Engineer'],
        [['quantity surveyor'], 'Quantity Surveyor'],
        [['building surveyor'], 'Building Surveyor'],
        [['building inspector'], 'Building Inspector'],
        [['site manager'], 'Site Manager'],
        [['construction', 'manager'], 'Construction Manager'],
        [['contracts manager'], 'Contracts Manager'],
        [['groundworker'], 'Groundworker'],
        [['scaffolder'], 'Scaffolder'],
        [['bricklayer'], 'Bricklayer'],
        [['plasterer'], 'Plasterer'],
        [['roofer'], 'Roofer'],
        [['tiler'], 'Tiler'],
        [['painter', 'decorator'], 'Painter'],
        [['paint sprayer'], 'Painter'],
        [['window', 'door', 'installer'], 'Window Installer'],
        [['window', 'installer'], 'Window Installer'],
        [['carpenter'], 'Carpenter'],
        [['multi trade'], 'Multi Trade Operative'],
        [['labourer'], 'Labourer'],
        [['cscs'], 'Labourer'],
        [['cnc'], 'CNC Machinist'],
        [['tig welder'], 'Welder'],
        [['welder'], 'Welder'],
        [['fabricator'], 'Welder'],
        [['machine operator'], 'Machine Operator'],
        [['registered nurse'], 'Nurse'],
        [['dental nurse'], 'Dental Nurse'],
        [['nurse'], 'Nurse'],
        [['complex care'], 'Care Assistant'],
        [['care assistant'], 'Care Assistant'],
        [['care worker'], 'Care Worker'],
        [['care coordinator'], 'Care Coordinator'],
        [['care team leader'], 'Senior Care Assistant'],
        [['senior care'], 'Senior Care Assistant'],
        [['healthcare assistant'], 'Healthcare Assistant'],
        [['clinical assessor'], 'Clinical Assessor'],
        [['functional assessor'], 'Clinical Assessor'],
        [['support worker'], 'Support Worker'],
        [['residential', 'support'], 'Residential Support Worker'],
        [['outreach worker'], 'Rough Sleeping Outreach Worker'],
        [['social worker'], 'Social Worker'],
        [['personal advisor'], 'Personal Advisor'],
        [['teaching assistant'], 'Teaching Assistant'],
        [['sen teacher'], 'SEN Teacher'],
        [['sen teaching'], 'Teaching Assistant'],
        [['supply teacher'], 'Supply Teacher'],
        [['primary teacher'], 'Primary Teacher'],
        [['english teacher'], 'Primary Teacher'],
        [['maths teacher'], 'Maths Teacher'],
        [['science teacher'], 'Science Teacher'],
        [['lecturer'], 'Lecturer'],
        [['headteacher'], 'Primary Teacher'],
        [['head of construction'], 'Lecturer'],
        [['cover supervisor'], 'Teaching Assistant'],
        [['learning support'], 'Teaching Assistant'],
        [['behaviour mentor'], 'Teaching Assistant'],
        [['field sales'], 'Field Sales Representative'],
        [['door to door'], 'Door Canvasser'],
        [['canvasser'], 'Door Canvasser'],
        [['car sales'], 'Sales Executive'],
        [['sales engineer'], 'Sales Engineer'],
        [['regional sales'], 'Regional Sales Manager'],
        [['area sales'], 'Regional Sales Manager'],
        [['sales manager'], 'Sales Manager'],
        [['sales director'], 'Sales Manager'],
        [['sales consultant'], 'Sales Consultant'],
        [['sales advisor'], 'Sales Advisor'],
        [['sales executive'], 'Sales Executive'],
        [['sales administrator'], 'Sales Administrator'],
        [['sales representative'], 'Sales Representative'],
        [['business development'], 'Business Development Manager'],
        [['account manager'], 'Account Manager'],
        [['key account'], 'Account Manager'],
        [['customer account'], 'Account Manager'],
        [['general manager'], 'General Manager'],
        [['operations manager'], 'Operations Manager'],
        [['area manager'], 'Area Manager'],
        [['deputy manager'], 'Deputy Manager'],
        [['assistant manager'], 'Assistant Manager'],
        [['assistant store'], 'Assistant Manager'],
        [['store manager'], 'Store Manager'],
        [['branch manager'], 'Branch Manager'],
        [['home manager'], 'Home Manager'],
        [['registered manager'], 'Registered Manager'],
        [['nursery manager'], 'Nursery Manager'],
        [['property manager'], 'Property Manager'],
        [['project manager'], 'Project Manager'],
        [['design manager'], 'Design Manager'],
        [['marketing manager'], 'Marketing Manager'],
        [['finance manager'], 'Finance Manager'],
        [['transport manager'], 'Transport Manager'],
        [['maintenance manager'], 'Maintenance Manager'],
        [['production manager'], 'Production Manager'],
        [['service manager'], 'Service Manager'],
        [['aftersales manager'], 'General Manager'],
        [['bid manager'], 'Bid Manager'],
        [['commercial manager'], 'Contracts Manager'],
        [['financial controller'], 'Financial Controller'],
        [['management accountant'], 'Management Accountant'],
        [['finance business partner'], 'Finance Business Partner'],
        [['head of finance'], 'Head of Finance'],
        [['credit controller'], 'Credit Controller'],
        [['accountant'], 'Accountant'],
        [['accounts assistant'], 'Finance Assistant'],
        [['finance assistant'], 'Finance Assistant'],
        [['payroll specialist'], 'Payroll Specialist'],
        [['payroll administrator'], 'Payroll Administrator'],
        [['payroll'], 'Payroll Administrator'],
        [['estimator'], 'Estimator'],
        [['buyer'], 'Buyer'],
        [['mortgage'], 'Mortgage Advisor'],
        [['hr business partner'], 'HR Business Partner'],
        [['hr advisor'], 'HR Advisor'],
        [['hr manager'], 'HR Advisor'],
        [['recruitment consultant'], 'Recruitment Consultant'],
        [['trainee recruitment'], 'Recruitment Consultant'],
        [['job coach'], 'Recruitment Consultant'],
        [['customer service'], 'Customer Service Advisor'],
        [['customer relations'], 'Customer Service Advisor'],
        [['receptionist'], 'Receptionist'],
        [['helpdesk'], 'Receptionist'],
        [['it apprentice'], 'IT Apprentice'],
        [['it support'], 'IT Support'],
        [['cyber security'], 'IT Support'],
        [['technical architect'], 'Solution Architect'],
        [['technical specialist'], 'IT Support'],
        [['chef'], 'Chef'],
        [['cook'], 'Cook'],
        [['kitchen assistant'], 'Kitchen Assistant'],
        [['kitchen designer'], 'Kitchen Designer'],
        [['chip shop'], 'Cook'],
        [['food & beverage'], 'Catering Assistant'],
        [['food coordinator'], 'Catering Assistant'],
        [['restaurant team'], 'Restaurant Team Member'],
        [['bar team'], 'Bartender'],
        [['pizza venue'], 'Cook'],
        [['burger venue'], 'Cook'],
        [['supermarket team'], 'Cashier'],
        [['customer assistant'], 'Cashier'],
        [['housekeeper'], 'Housekeeper'],
        [['housekeeping'], 'Housekeeper'],
        [['vehicle technician'], 'Vehicle Technician'],
        [['motor vehicle'], 'Vehicle Technician'],
        [['mechanic'], 'Mechanic'],
        [['tyre fitter'], 'Mobile Tyre Fitter'],
        [['parts advisor'], 'Parts Advisor'],
        [['service advisor'], 'Service Advisor'],
        [['workshop controller'], 'Workshop Controller'],
        [['workshop technician'], 'Workshop Technician'],
        [['warehouse'], 'Warehouse Operative'],
        [['transport planner'], 'Transport Planner'],
        [['security officer'], 'Security Officer'],
        [['security'], 'Security Officer'],
        [['sia'], 'Security Officer'],
        [['production operative'], 'Production Operative'],
        [['production supervisor'], 'Production Supervisor'],
        [['cleaning operative'], 'Cleaner'],
        [['cleaner'], 'Cleaner'],
        [['cleaning'], 'Cleaner'],
        [['research'], 'Research Associate'],
        [['postdoctoral'], 'Research Associate'],
        [['professor'], 'Lecturer'],
        [['nursery practitioner'], 'Nursery Practitioner'],
        [['nursery'], 'Nursery Practitioner'],
        [['plumber'], 'Plumber'],
        [['electrician'], 'Electrician'],
        [['mechanical fitter'], 'Mechanical Fitter'],
        [['installer'], 'Installer'],
        [['document controller'], 'Document Controller'],
        [['technical author'], 'Technical Author'],
        [['digital marketing'], 'Digital Marketing Executive'],
        [['head of marketing'], 'Head of Marketing'],
        [['cad technician'], 'CAD Technician'],
        [['architectural'], 'Architect'],
        [['architect'], 'Architect'],
        [['ecologist'], 'Ecologist'],
        [['lifeguard'], 'Lifeguard'],
        [['fundraiser'], 'Fundraiser'],
        [['compliance'], 'Compliance Officer'],
        [['planning officer'], 'Planning Officer'],
        [['planning enforcement'], 'Planning Officer'],
        [['housing officer'], 'Property Manager'],
        [['library assistant'], 'Administrator'],
        [['contractor escort'], 'Security Officer'],
        [['school crossing'], 'School Crossing Patrol'],
        [['passenger assistant'], 'Passenger Assistant'],
        [['activities coordinator'], 'Activities Coordinator'],
        [['activities team'], 'Activities Coordinator'],
        [['team leader'], 'Team Leader'],
        [['shift leader'], 'Shift Leader'],
        [['supervisor'], 'Supervisor'],
        [['administrator'], 'Administrator'],
        [['admin'], 'Administrator'],
        [['coordinator'], 'Administrator'],
        [['legal secretary'], 'Legal Secretary'],
        [['secretary'], 'Legal Secretary'],
        [['costs draftsperson'], 'Legal Secretary'],
        [['surveyor'], 'Building Surveyor'],
        [['engineer'], 'Maintenance Engineer'],
        [['technician'], 'Maintenance Technician'],
        [['operative'], 'Factory Operative'],
        [['driver'], 'Delivery Driver'],
        [['manager'], 'General Manager'],
        [['assistant'], 'Administrator'],
        [['teacher'], 'Primary Teacher'],
        [['worker'], 'Support Worker'],
        [['multi-skilled', 'engineer'], 'Maintenance Engineer'],
        [['green & clean'], 'Cleaner'],
        [['green and clean'], 'Cleaner'],
        [["children's home"], 'Residential Support Worker'],
        [['childrens home'], 'Residential Support Worker'],
        [['tenancy support'], 'Support Worker'],
        [['process technologist'], 'Quality Engineer'],
        [['digital transformation'], 'IT Support'],
        [['software developer'], 'Software Engineer'],
        [['data scientist'], 'Data Analyst'],
        [['kitchen porter'], 'Kitchen Assistant'],
        [['merchandiser'], 'Sales Advisor'],
        [['joiner'], 'Carpenter'],
        [['marketing executive'], 'Digital Marketing Executive'],
        [['conveyancer'], 'Legal Secretary'],
        [['valeter'], 'Cleaner'],
        [['site agent'], 'Site Manager'],
        [['consultant'], 'Account Manager'],
        [['analyst'], 'Data Analyst'],
        [['clerk'], 'Finance Assistant'],
        [['developer'], 'Software Engineer'],
        [['director'], 'General Manager'],
        [['officer'], 'Administrator'],
        [['adviser'], 'Customer Service Advisor'],
        [['advisor'], 'Customer Service Advisor'],
        [['carer'], 'Care Worker'],
        [['porter'], 'Warehouse Operative'],
        [['agent'], 'Sales Advisor'],
        [['member'], 'Cashier'],
        [['registrar'], 'Administrator'],
        [['principal'], 'General Manager'],
        [['head of'], 'General Manager'],
        [['senior'], 'General Manager'],
        [['lead'], 'General Manager'],
    ];

    /**
     * Content-based spam signals derived from sampling the 225k-job WhatJobs
     * feed (2026-05-27). Each pattern is precision-tuned against verified
     * spam advertisers (ApexFocusGroup, Cashback.co.uk, Reps.co.uk/Utility
     * Warehouse MLM) and verified legitimate ones (EE, Brakes, Evri, Durham
     * Univ, My Four Wheels). Any one pattern in isolation has too many
     * false-positives — e.g. `earn_per_unit` hits legitimate "earn £46,500"
     * driver listings from Brakes — but two or more matches together had
     * zero false positives across the top 9 legitimate companies in the
     * 225k-job sample. Drops 43k of 225k feed jobs (19%).
     */
    private const SPAM_PATTERNS = [
        'focus_group'    => '/\bfocus\s+group\b|\bpanell?ists?\b/i',
        'now_accepting'  => '/\bnow\s+accepting\b/i',
        'earn_per_unit'  => '/\bearn\b[^.]{0,30}\bper\s+(week|day|hour|month)\b/i',
        'research_study' => '/\bresearch\s+study\s+opportunity\b/i',
        'flex_earnings'  => '/\bflexible\s+earnings?\s+opportunity\b/i',
        'be_own_boss'    => '/\bbe\s+your\s+own\s+boss\b|\bwork\s+your\s+own\s+hours\b/i',
        'money_range_pm' => '/[£$]\s*\d{2,5}\s*[-–]\s*[£$]?\s*\d{3,6}\+?\s*(per\s+month|\/\s*month)/i',
        'mlm_brand'      => '/\b(utility\s+warehouse|amway|forever\s+living|tupperware|herbalife)\b/i',
        'paid_survey'    => '/\bpaid\s+(surveys?|focus\s+groups?)\b/i',
    ];

    private const SPAM_SCORE_THRESHOLD = 2;

    /** @var array<string, int|array> per-run drop counts; reset at start of sync() */
    private array $dropStats = [];

    /**
     * Increment a geocode-fail bucket and tally per-tuple frequency.
     * The frequency map lets us spot ambiguous-name false-positives later: if
     * 285k failures collapse to a few thousand distinct (city|state|country)
     * tuples, a per-tuple override or postcode fallback can recapture most.
     */
    private function recordGeocodeFail(string $reason, string $city, string $state, string $country): void
    {
        $this->dropStats['geocode_by_reason'][$reason] = ($this->dropStats['geocode_by_reason'][$reason] ?? 0) + 1;

        $key = "$city|$state|$country";
        $this->dropStats['geocode_tuple_freq'][$reason][$key]
            = ($this->dropStats['geocode_tuple_freq'][$reason][$key] ?? 0) + 1;
    }

    public function sync(bool $dryRun = false): array
    {
        $this->dropStats = [];
        $srid  = config('freegle.srid', 3857);
        $feed1 = config('freegle.whatjobs.feed1');
        $feed2 = config('freegle.whatjobs.feed2');

        $geocodeCache = [];

        // V1 (cron/whatjobs.php → Jobs::scanToCSV) uses format=1 for the WhatJobs
        // feed and format=2 for the clickcast feed. parseFeed's format=1 branch
        // expects WhatJobs field names (locations->location->location, urlDeeplink,
        // company->name, custom->CPC, id, timePosted), and format=2 expects the
        // clickcast names.
        //
        // parseFeed yields each job as it parses the XML, so we stream straight
        // from XML → DB. Holding the full ~180k-job result in PHP memory used
        // to FatalError at 512M; this keeps peak memory at one batch (~200 rows).
        // Ask the feeds whether anything actually changed before paying for a rebuild.
        // A dry run is a diagnostic, so it always parses.
        $gate = $this->evaluateFeedGate(array_filter([$feed1, $feed2]), $dryRun);

        if ($gate['skip']) {
            Log::info('WhatJobs sync skipped - feeds unchanged', [
                'reasons'      => $gate['reasons'],
                'last_rebuild' => $gate['last_rebuild'],
            ]);

            return [
                'total'    => 0,
                'inserted' => 0,
                'skipped_unchanged' => true,
                'reasons'  => $gate['reasons'],
            ];
        }

        $tmp1 = $feed1 ? ($gate['paths'][$feed1] ?? null) : null;
        $tmp2 = $feed2 ? ($gate['paths'][$feed2] ?? null) : null;

        // Build a single generator that yields from both feeds in sequence so
        // insertJobs() sees one continuous stream (and the second feed's geocode
        // lookups can still hit the cache populated by the first).
        $streamJobs = function () use ($tmp1, $tmp2, &$geocodeCache): \Generator {
            if ($tmp1) {
                yield from $this->parseFeed($tmp1, 1, $geocodeCache);
            }
            if ($tmp2) {
                yield from $this->parseFeed($tmp2, 2, $geocodeCache);
            }
        };

        if ($dryRun) {
            // Walk the generator to populate dropStats but don't write anything.
            $total = 0;
            foreach ($streamJobs() as $_) {
                $total++;
            }
            if ($tmp1) { @unlink($tmp1); }
            if ($tmp2) { @unlink($tmp2); }
            $this->logParseStats($total);
            Log::info('WhatJobs dry run', ['total_jobs' => $total]);
            return ['total' => $total, 'inserted' => 0, 'dry_run' => true];
        }

        // Rebuild jobs_keywords from clicks on the *current* (pre-swap) live
        // table BEFORE inserting, so insertJobs() can score clickability inline
        // and set it on the INSERT. Keyword frequency is a slow-moving 31-day
        // click signal, independent of the incoming feed, so computing it from
        // the outgoing table is correct — and it lets us drop the old post-swap
        // updateClickability() pass that hammered Galera with ~1M UPDATEs.
        $this->analyseClickability();

        // Real run: stream jobs into jobs_new in batches, then decide whether
        // to swap based on the inserted row count.
        $this->prepareTempTable();
        $inserted = $this->insertJobs($streamJobs(), $srid);
        if ($tmp1) { @unlink($tmp1); }
        if ($tmp2) { @unlink($tmp2); }
        $this->logParseStats($inserted);

        // Defensive: swapTables() RENAMEs jobs_new over jobs, so a swap with too
        // few parsed jobs wipes the live table. Refuse if 0 parsed (feed
        // download likely 429d/empty) or if the count dropped to less than
        // self::MIN_SWAP_RATIO of the existing row count (when the existing
        // table is large enough to compare). The check moves AFTER insert
        // now — we need the count, and counting jobs_new is cheap.
        $existing = (int) DB::table('jobs')->count();
        if ($inserted === 0 || ($existing >= self::SWAP_RATIO_MIN_EXISTING && $inserted < $existing * self::MIN_SWAP_RATIO)) {
            Log::warning('WhatJobs: refusing to swap — parsed too few jobs', [
                'parsed' => $inserted,
                'existing' => $existing,
                'min_ratio' => self::MIN_SWAP_RATIO,
            ]);
            Schema::dropIfExists('jobs_new');
            return ['total' => $inserted, 'inserted' => 0, 'skipped_swap' => true, 'existing' => $existing];
        }

        $this->swapTables();

        // Only now, with the table actually rebuilt, are the new validators safe to
        // keep. Storing them earlier would mean a refused swap (see above) left us
        // believing the live table already matched the feed, and the gate would skip
        // every run after it.
        $this->commitFeedGateState($gate['state']);

        // The swap drops rows for postings that have closed/left the feed. The
        // spatial server's "jobs" index backs both the web jobs page and the
        // digest (since #764), and its incremental delta only adds/updates — it
        // never removes vanished ids. Without an explicit rebuild the index
        // keeps serving stale ids (gone, or remapped to a different posting)
        // until the nightly 03:00 rebuild, so clicks land on the wrong/closed
        // job and don't convert to billable. Trigger a rebuild now so the index
        // matches the freshly-swapped table.
        app(SpatialAdminService::class)->rebuildDataset('jobs');

        Log::info('WhatJobs sync complete', ['total' => $inserted, 'inserted' => $inserted]);

        return ['total' => $inserted, 'inserted' => $inserted, 'dry_run' => false];
    }

    /**
     * Emit the per-tuple geocode/drop summary collected during parsing.
     * Pulled out of sync() so both dryRun and real-run paths use the same
     * format.
     */
    protected function logParseStats(int $kept): void
    {
        $tupleSummary = [];
        foreach (($this->dropStats['geocode_tuple_freq'] ?? []) as $reason => $freq) {
            arsort($freq);
            $top = array_slice($freq, 0, 20, true);
            $tupleSummary[$reason] = [
                'distinct_tuples' => count($freq),
                'top_20'          => $top,
            ];
        }

        Log::info('WhatJobs parse drop stats', [
            'kept'                => $kept,
            'low_cpc'             => $this->dropStats['low_cpc']      ?? 0,
            'too_old'             => $this->dropStats['too_old']      ?? 0,
            'spam_content'        => $this->dropStats['spam_content'] ?? 0,
            'geocode_fail'        => $this->dropStats['geocode_fail'] ?? 0,
            'no_jobid'            => $this->dropStats['no_jobid']     ?? 0,
            'too_old_by_cpc'      => $this->dropStats['too_old_by_cpc']      ?? [],
            'geocode_fail_by_cpc' => $this->dropStats['geocode_fail_by_cpc'] ?? [],
            'no_jobid_by_cpc'     => $this->dropStats['no_jobid_by_cpc']     ?? [],
            'geocode_by_reason'   => $this->dropStats['geocode_by_reason']   ?? [],
            'geocode_tuples'      => $tupleSummary,
        ]);
    }

    /**
     * Score a job's title+body against SPAM_PATTERNS. Returns true when at
     * least SPAM_SCORE_THRESHOLD patterns match. Single-pattern hits are
     * allowed because legitimate listings often hit one in isolation
     * (e.g. a Brakes driver "earn £46,500" listing hits earn_per_unit alone).
     */
    public function isSpamJob(string $title, string $body): bool
    {
        $text = $title . ' ' . $body;
        $matches = 0;
        foreach (self::SPAM_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                $matches++;
                if ($matches >= self::SPAM_SCORE_THRESHOLD) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * ETag/Last-Modified from the last downloadFeed() response, recorded so the gate can
     * report what the feed claims about itself. Nothing acts on these yet - see fetchFeed.
     *
     * @var array{etag: ?string, last_modified: ?string}|null
     */
    protected ?array $lastFeedHeaders = null;

    protected function downloadFeed(string $url): ?string
    {
        $gzFile  = tempnam(sys_get_temp_dir(), 'whatjobs_gz_');
        $xmlFile = tempnam(sys_get_temp_dir(), 'whatjobs_xml_');

        try {
            // Stream the gzipped feed straight to disk via Guzzle's sink option.
            // Without this, Http::body() buffers the whole response (hundreds of MB) into PHP memory.
            $response = Http::timeout(1200)
                ->withOptions(['sink' => $gzFile])
                ->get($url);
            if (!$response->successful()) {
                Log::warning('WhatJobs feed download failed', ['url' => $url, 'status' => $response->status()]);
                @unlink($gzFile);
                @unlink($xmlFile);
                return null;
            }

            $this->lastFeedHeaders = [
                'etag' => $response->header('ETag') ?: null,
                'last_modified' => $response->header('Last-Modified') ?: null,
            ];

            // Stream-decompress to avoid loading everything into memory
            $gz  = gzopen($gzFile, 'rb');
            $out = fopen($xmlFile, 'wb');
            while (!gzeof($gz)) {
                fwrite($out, gzread($gz, 65536));
            }
            gzclose($gz);
            fclose($out);
            @unlink($gzFile);

            return $xmlFile;
        } catch (\Throwable $e) {
            Log::warning('WhatJobs feed download error', ['url' => $url, 'error' => $e->getMessage()]);
            @unlink($gzFile);
            @unlink($xmlFile);
            return null;
        }
    }

    /**
     * Fetch one feed and say whether it is worth reparsing.
     *
     * WhatJobs regenerates its feed roughly once a day, but we sync six times a day, so
     * most runs reparse content we already loaded: three consecutive runs one day produced
     * byte-identical parse fingerprints, kept counts matching to the digit. Each of those
     * pointless runs costs 25-45 minutes of batch-host CPU, around a gigabyte of row images
     * replicated to every Galera node, and a rebuild of the spatial jobs index on each db
     * host.
     *
     * The test is a hash of the feed's own content. The feed's ETag is recorded alongside
     * it but nothing acts on it: an ETag comes back through a CDN, which can preserve one
     * across a genuine regeneration, and the agreed rollout is to watch the decisions this
     * makes for a week before trusting a 304 to skip a transfer. Hashing what we downloaded
     * settles the question ourselves, and the download was never the expensive part.
     *
     * The hash is over the decompressed XML rather than the gzip, because gzip's header
     * carries a modification time - identical content recompressed is different bytes.
     *
     * $prev is what we stored last time; an empty array forces "changed".
     *
     * status: 'downloaded' (reparse it) | 'unchanged' (skip if the other feeds agree) |
     *         'failed' (fail open - run the full pipeline)
     */
    protected function fetchFeed(string $url, array $prev): array
    {
        $this->lastFeedHeaders = null;

        // Deliberately routed through downloadFeed so there is a single place that knows
        // how to obtain a feed.
        $path = $this->downloadFeed($url);

        if ($path === null) {
            return ['status' => 'failed', 'reason' => 'download-failed', 'path' => null];
        }

        $hash = @hash_file('sha256', $path) ?: null;
        $identical = $hash !== null && isset($prev['hash']) && $prev['hash'] === $hash;

        return [
            'status'        => $identical ? 'unchanged' : 'downloaded',
            'reason'        => $identical ? 'identical-content' : 'changed',
            'path'          => $path,
            'etag'          => $this->lastFeedHeaders['etag'] ?? null,
            'last_modified' => $this->lastFeedHeaders['last_modified'] ?? null,
            'hash'          => $hash,
        ];
    }

    /**
     * Decide whether this run has anything to do.
     *
     * It skips only when EVERY configured feed says unchanged, because the sync
     * rebuilds the whole jobs table from all feeds at once - one changed feed means
     * the whole pipeline runs.
     *
     * The gate fails open throughout: a download error, an unreadable stored state,
     * a dry run, --force, or a rebuild older than MAX_SKIP_HOURS all produce a normal
     * full run. The worst a broken gate can do is cost what today already costs.
     */
    protected function evaluateFeedGate(array $urls, bool $dryRun): array
    {
        $state = $this->readFeedGateState();
        $lastRebuild = $state['last_rebuild'] ?? null;

        $bypass = null;
        if ($dryRun) {
            $bypass = 'dry-run';
        } elseif ($this->forceFullSync) {
            $bypass = 'forced';
        } elseif (!$urls) {
            $bypass = 'no-feeds';
        } elseif ($lastRebuild === null) {
            $bypass = 'no-previous-rebuild';
        } elseif (strtotime($lastRebuild) < strtotime('-' . self::MAX_SKIP_HOURS . ' hours')) {
            // Guaranteed rebuild: see MAX_SKIP_HOURS.
            $bypass = 'rebuild-overdue';
        }

        $results = [];
        foreach ($urls as $url) {
            $prev = $bypass ? [] : ($state['feeds'][$this->feedStateKey($url)] ?? []);
            $results[$url] = $this->fetchFeed($url, $prev);
        }

        $newState = $state;
        $newState['feeds'] = $state['feeds'] ?? [];
        $reasons = [];
        $allUnchanged = $urls !== [];

        foreach ($results as $url => $r) {
            $reasons[$this->feedStateKey($url)] = $r['reason'] ?? $r['status'];
            if ($r['status'] !== 'unchanged') {
                $allUnchanged = false;
            }
            if ($r['status'] !== 'failed') {
                $newState['feeds'][$this->feedStateKey($url)] = [
                    'etag'          => $r['etag'] ?? null,
                    'last_modified' => $r['last_modified'] ?? null,
                    'hash'          => $r['hash'] ?? null,
                ];
            }
        }

        $skip = $bypass === null && $allUnchanged;

        if ($skip) {
            // Nothing will be parsed, so drop the files we did fetch, and record that
            // the gate ran. commitFeedGateState is NOT called here: last_rebuild must
            // keep pointing at the last real rebuild, or the MAX_SKIP_HOURS floor
            // would never trigger.
            foreach ($results as $r) {
                if (!empty($r['path'])) {
                    @unlink($r['path']);
                }
            }
            $newState['last_checked'] = now()->toDateTimeString();
            $this->writeFeedGateState($newState);
        }

        $paths = [];
        foreach ($results as $url => $r) {
            $paths[$url] = $r['path'] ?? null;
        }

        return [
            'skip'         => $skip,
            'bypass'       => $bypass,
            'reasons'      => $reasons,
            'paths'        => $paths,
            'last_rebuild' => $lastRebuild,
            'state'        => $newState,
        ];
    }

    /**
     * Short stable identifier for a feed URL, so the stored state does not carry
     * credentials that some feed URLs embed as query parameters.
     */
    protected function feedStateKey(string $url): string
    {
        return substr(hash('sha256', $url), 0, 16);
    }

    protected function readFeedGateState(): array
    {
        try {
            $raw = DB::table('config')->where('key', self::GATE_CONFIG_KEY)->value('value');
            $decoded = $raw ? json_decode($raw, true) : null;

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            // Fail open - an unreadable state just means a full run.
            Log::warning('WhatJobs: could not read feed gate state', ['error' => $e->getMessage()]);

            return [];
        }
    }

    protected function writeFeedGateState(array $state): void
    {
        try {
            DB::table('config')->upsert(
                [['key' => self::GATE_CONFIG_KEY, 'value' => json_encode($state)]],
                ['key'],
                ['value'],
            );
        } catch (\Throwable $e) {
            Log::warning('WhatJobs: could not store feed gate state', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record a completed rebuild: the validators the feeds gave us this run, plus the
     * timestamp the MAX_SKIP_HOURS floor is measured from.
     */
    protected function commitFeedGateState(array $state): void
    {
        $state['last_rebuild'] = now()->toDateTimeString();
        $state['last_checked'] = $state['last_rebuild'];
        $this->writeFeedGateState($state);
    }

    /**
     * Parse a WhatJobs / Clickcast XML feed.
     *
     * Yields job arrays one at a time so callers can stream them into
     * insertJobs() without holding the full ~180k-job result in memory
     * (which used to FatalError at 512M).
     *
     * @return \Generator<array<string,mixed>>
     */
    public function parseFeed(string $filePath, int $format, array &$geocodeCache): \Generator
    {
        $now    = now()->format('Y-m-d H:i:s');
        $cutoff = now()->subDays(self::MAX_AGE_DAYS)->timestamp;

        $reader = new \XMLReader();
        if (!$reader->open($filePath)) {
            Log::warning('WhatJobs: failed to open feed file', ['path' => $filePath]);
            return;
        }

        $count   = 0;
        $hasNode = $reader->read();
        while ($hasNode) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== 2) {
                $hasNode = $reader->read();
                continue;
            }

            // expand($doc) imports the current node into $doc so ownerDocument is never null.
            // Without a DOMDocument argument, ownerDocument can be null on some libxml2 builds.
            $doc     = new \DOMDocument();
            $node    = $reader->expand($doc);
            $hasNode = $reader->next();
            if (!$node) {
                continue;
            }
            $xmlStr = $doc->saveXML($node);

            $job = @simplexml_load_string($xmlStr);
            if (!$job) {
                continue;
            }

            if ($format === 1) {
                $location    = (string) ($job->locations->location->location ?? '');
                $timePosted  = (string) ($job->timePosted ?? '');
                $city        = (string) ($job->locations->location->city ?? '');
                $state       = (string) ($job->locations->location->state ?? '');
                $zip         = (string) ($job->locations->location->zip ?? '');
                $country     = (string) ($job->locations->location->country ?? '');
                $company     = (string) ($job->company->name ?? '');
                $cpc         = (string) ($job->custom->CPC ?? '0');
                $deeplink    = (string) ($job->urlDeeplink ?? '');
                $jobId       = (string) ($job->id ?? '');
                $title       = (string) ($job->title ?? '');
                $description = (string) ($job->description ?? '');
                $category    = (string) ($job->category ?? '');
            } else {
                $location    = (string) ($job->location ?? '');
                $timePosted  = (string) ($job->posted_at ?? '');
                $city        = (string) ($job->city ?? '');
                $state       = (string) ($job->state ?? '');
                $zip         = (string) ($job->zip ?? '');
                $country     = (string) ($job->country ?? '');
                $company     = (string) ($job->company ?? '');
                $cpc         = (string) ($job->cpc ?? '0');
                $deeplink    = (string) ($job->url ?? '');
                $jobId       = (string) ($job->job_reference ?? '');
                $title       = (string) ($job->title ?? '');
                $description = (string) ($job->body ?? '');
                $category    = (string) ($job->category ?? '');
            }

            // Bucket the CPC so the run-end stats (logged below) can show how
            // many high-paying listings each filter is dropping.
            $cpcF      = (float) $cpc;
            $cpcBucket = $cpcF >= 1.00 ? 'cpc_ge_1' : ($cpcF >= 0.50 ? 'cpc_50_99' : ($cpcF >= 0.10 ? 'cpc_10_49' : 'cpc_lt_10'));

            if (!$jobId) {
                $this->dropStats['no_jobid'] = ($this->dropStats['no_jobid'] ?? 0) + 1;
                $this->dropStats['no_jobid_by_cpc'][$cpcBucket] = ($this->dropStats['no_jobid_by_cpc'][$cpcBucket] ?? 0) + 1;
                continue;
            }
            if ($cpcF < self::MINIMUM_CPC) {
                $this->dropStats['low_cpc'] = ($this->dropStats['low_cpc'] ?? 0) + 1;
                continue;
            }
            if ($timePosted && strtotime($timePosted) < $cutoff) {
                $this->dropStats['too_old'] = ($this->dropStats['too_old'] ?? 0) + 1;
                $this->dropStats['too_old_by_cpc'][$cpcBucket] = ($this->dropStats['too_old_by_cpc'][$cpcBucket] ?? 0) + 1;
                continue;
            }
            if ($this->isSpamJob($title, $description)) {
                $this->dropStats['spam_content'] = ($this->dropStats['spam_content'] ?? 0) + 1;
                continue;
            }

            $geom = $this->geocodeCityState($city, $state, $country, $geocodeCache, $zip);
            if (!$geom) {
                $this->dropStats['geocode_fail'] = ($this->dropStats['geocode_fail'] ?? 0) + 1;
                $this->dropStats['geocode_fail_by_cpc'][$cpcBucket] = ($this->dropStats['geocode_fail_by_cpc'][$cpcBucket] ?? 0) + 1;
                continue;
            }

            [$swlat, $swlng, $nelat, $nelng, $geomWkt] = $geom;

            // Randomise within bbox to avoid clustering (matches the legacy V1 PHP behaviour)
            $newlat  = $swlat + (mt_rand() / mt_getrandmax()) * ($nelat - $swlat);
            $newlng  = $swlng + (mt_rand() / mt_getrandmax()) * ($nelng - $swlng);
            $geomWkt = $this->boxPoly(
                $newlat - self::DISTRIBUTE,
                $newlng - self::DISTRIBUTE,
                $newlat + self::DISTRIBUTE,
                $newlng + self::DISTRIBUTE
            );

            $body = $this->cleanBody($description);
            $titleClean = $title ? html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;

            yield [
                'location'        => $location ? $this->titleCaseLocation(html_entity_decode($location, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null,
                'title'           => $titleClean,
                'city'            => $city ? html_entity_decode($city, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
                'state'           => $state ? html_entity_decode($state, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
                'zip'             => $zip ? html_entity_decode($zip, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
                'country'         => $country ? html_entity_decode($country, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
                'job_type'        => null,
                'posted_at'       => $timePosted ? html_entity_decode($timePosted, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
                'job_reference'   => html_entity_decode($jobId, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'company'         => $company ? html_entity_decode($company, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
                'category'        => $category ? html_entity_decode($category, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
                'url'             => $deeplink ? html_entity_decode($deeplink, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null,
                'body'            => $body,
                'cpc'             => (float) $cpc,
                'geometry'        => $geomWkt,
                'clickability'    => 1,
                'bodyhash'        => $body ? md5($body) : null,
                'seenat'          => $now,
                'visible'         => 1,
                'canonical_title' => $this->canonicalJobTitle($titleClean),
            ];

            $count++;
            if ($count % 1000 === 0) {
                Log::debug('WhatJobs: parsing progress', ['count' => $count]);
            }
        }

        $reader->close();
    }

    // ISO 3166-2 two/three-letter subdivision codes used by some feeds
    private const STATE_ISO_CODES = ['eng', 'wls', 'sct', 'nir', 'gb'];

    /**
     * When true, geocodeCityState skips the jobs-table geocode cache and
     * re-resolves each tuple from postcode/Photon. Set for a one-time
     * --refresh-geocode sync to retro-correct tuples mis-cached by the old
     * inverted-extent bug; off in normal hourly runs (which keep the cache).
     */
    public bool $forceRegeocode = false;

    /**
     * When true, the feed-change gate is bypassed and the feeds are reparsed and
     * reloaded whatever they contain. Set by --force, and used for the guaranteed
     * rebuild the gate schedules for itself (see MAX_SKIP_HOURS).
     *
     * A public property rather than a sync() argument because sync()'s signature is
     * an override point for tests.
     */
    public bool $forceFullSync = false;

    public function geocodeCityState(
        string $city,
        string $state,
        string $country,
        array &$cache,
        string $zip = ''
    ): ?array {
        if ($country === 'Guernsey') {
            $this->recordGeocodeFail('country_guernsey', $city, $state, $country);
            return null;
        }

        // Resolve the UK outward postcode up front, if the feed gave us one.
        // It is used two ways below:
        //  (a) it keys the in-memory cache, so a job WITH a postcode never
        //      inherits a (possibly wrong) city/state geocode that an earlier
        //      same-(city,state,country) job WITHOUT a postcode cached; and
        //  (b) it is tried FIRST, before the self-poisoning jobs-table cache
        //      and the ambiguous Photon city/state lookups.
        $outward  = $zip !== '' ? $this->extractOutwardCode($zip) : '';
        $cacheKey = $outward !== '' ? "$city,$state,$country|$outward" : "$city,$state,$country";

        // array_key_exists (not isset) so cached negative results (null)
        // are reused — Photon currently rate-limits us with HTTP 429, so
        // a tuple that fails the first lookup will keep failing every
        // time it appears in the feed. With 60k+ distinct (city,state,
        // country) tuples averaging 3-4 occurrences each, caching null
        // turns ~225k Photon calls into ~60k.
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        // POSTCODE-FIRST. A UK outward code pins the job to the right district
        // deterministically, so prefer it over both the self-poisoning
        // jobs-table cache and the ambiguous Photon city/state lookups. This is
        // the primary fix for "jobs in the wrong place" (Discourse #9692/#24):
        // e.g. "Conington, East of England" with zip PE29 3TN lands in
        // Cambridgeshire, not a same-named place Photon returns near London.
        // Sampling the live feed, jobs that DO carry a postcode previously had a
        // stored geometry a mean ~80km (max ~1400km) from their own postcode
        // centroid, with 56% over 50km out; this removes that error for them.
        if ($outward !== '') {
            $postcodeResult = $this->geocodePostcode($outward);
            if ($postcodeResult) {
                $cache[$cacheKey] = $postcodeResult;
                return $postcodeResult;
            }
        }

        // Check DB cache first (existing geocoded job with same city/state/country).
        // Skipped during a one-time --refresh-geocode run so previously mis-cached
        // tuples (e.g. the inverted-extent London placements) re-geocode fresh
        // instead of inheriting their own wrong point. The per-run in-memory cache
        // above still dedupes, so each tuple is geocoded at most once per run.
        if (!$this->forceRegeocode) {
            // keep-raw: ST_AsText/ST_Envelope are spatial functions the query
            // builder has no method for.
            $geo = DB::select(
                "SELECT ST_AsText(ST_Envelope(geometry)) AS geom FROM jobs
                 WHERE city = ? AND state = ? AND country = ? LIMIT 1",
                [$city, $state, $country]
            );

            if (count($geo) && $geo[0]->geom) {
                $bbox = $this->bboxFromWkt($geo[0]->geom);
                if ($bbox) {
                    $cache[$cacheKey] = $bbox;
                    return $bbox;
                }
            }
        }

        $badStates = [
            'not specified', 'united kingdom of great britain and northern ireland',
            'united kingdom', 'uk', 'england', 'scotland', 'wales', 'home based',
            'northern ireland',
        ];
        $badStates = array_merge($badStates, self::STATE_ISO_CODES);

        $result      = null;
        $stateBbox   = null;
        $stateUsable = $state && strlen(trim($state)) && !in_array(strtolower(trim($state)), $badStates);
        $stateTried  = false;

        if ($stateUsable) {
            $stateTried = true;
            $stateClean = str_ireplace('Borough of ', '', $state);
            $stateBbox  = $this->geocodeAddress($stateClean, false, true);

            if ($stateBbox) {
                $area = ($stateBbox[2] - $stateBbox[0]) * abs($stateBbox[3] - $stateBbox[1]);
                if ($area < 0.05) {
                    // Small area — specific location, use directly
                    $result = $stateBbox;
                } else {
                    // Large region — try to find the city within its bbox
                    $cityResult = $this->tryCityVariants(
                        $city,
                        $state,
                        $stateBbox[0], $stateBbox[1], $stateBbox[2], $stateBbox[3]
                    );
                    $result = $cityResult ?: $stateBbox;
                }
            }
        }

        // City-only fallback with UK-wide bbox
        $badCities     = ['not specified', 'null', 'home based', 'united kingdom', ', , united kingdom'];
        $cityUsable    = $city && strlen(trim($city)) && !in_array(strtolower(trim($city)), $badCities);
        $cityTried     = false;
        $cityTooCoarse = false;

        if (!$result && $cityUsable) {
            $cityTried = true;
            $result = $this->tryCityVariants($city, $state);
            if ($result) {
                $area = ($result[2] - $result[0]) * abs($result[3] - $result[1]);
                if ($area > 50) {
                    $cityTooCoarse = true;
                    $result = null;
                }
            }
        }

        // (The postcode is resolved FIRST, above — no trailing fallback needed.)

        if (!$result) {
            // Categorise the failure for observability.
            if (!$stateUsable && !$cityUsable) {
                $reason = 'unusable_input';
            } elseif ($cityTooCoarse) {
                $reason = 'city_too_coarse';
            } elseif ($cityTried) {
                $reason = 'city_no_match';
            } elseif ($stateTried) {
                $reason = 'state_no_match_no_city';
            } else {
                $reason = 'other';
            }
            $this->recordGeocodeFail($reason, $city, $state, $country);
        }

        // Always cache, even on miss. The negative-cache key here is what
        // makes the array_key_exists check above pay off.
        $cache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Try several strategies to geocode a city name within an optional bbox.
     *
     * Strategies tried in order:
     *  1. Each slash-separated segment (feed sometimes sends "Town A / Town B / ...")
     *  2. For each segment: as-is, then title-cased
     *  3. Combined "city, state" query (only for single, unambiguous city names)
     *
     * Returns the first successful result, or null.
     */
    private function tryCityVariants(
        string $city,
        string $state,
        float $bbswlat = self::UK_SWLAT,
        float $bbswlng = self::UK_SWLNG,
        float $bbnelat = self::UK_NELAT,
        float $bbnelng = self::UK_NELNG
    ): ?array {
        $segments = str_contains($city, '/')
            ? array_map('trim', explode('/', $city))
            : [$city];

        foreach ($segments as $seg) {
            if (!$seg || mb_strlen(trim($seg)) < 2) {
                continue;
            }

            // Town/settlement layers only — keeps a town name off waterways,
            // counties and landmarks (see geocodeAddress).
            $cityLayers = ['city', 'locality', 'district'];
            $result = $this->geocodeAddress($seg, true, false, $bbswlat, $bbswlng, $bbnelat, $bbnelng, $cityLayers);
            if ($result) {
                return $result;
            }

            // Try title-cased variant (feed often sends all-lowercase cities)
            $titled = ucwords(mb_strtolower($seg));
            if ($titled !== $seg) {
                $result = $this->geocodeAddress($titled, true, false, $bbswlat, $bbswlng, $bbnelat, $bbnelng, $cityLayers);
                if ($result) {
                    return $result;
                }
            }
        }

        // Combined "city, state" query to help disambiguate (e.g. "Kenwyn, Cornwall")
        // Only for single city names (slash lists are too ambiguous for this)
        if (!str_contains($city, '/') && $state) {
            $combined = trim($city) . ', ' . trim($state);
            $result   = $this->geocodeAddress($combined, true, false, $bbswlat, $bbswlng, $bbnelat, $bbnelng);
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Extract the outward code from a UK postcode string.
     * Handles full postcodes ("TR1 1AA" → "TR1") and outward-only ("TR1" → "TR1").
     */
    private function extractOutwardCode(string $zip): string
    {
        $zip = strtoupper(trim($zip));
        if (preg_match('/^([A-Z]{1,2}\d{1,2}[A-Z]?)\s*\d[A-Z]{2}$/', $zip, $m)) {
            return $m[1];
        }
        if (preg_match('/^[A-Z]{1,2}\d{1,2}[A-Z]?$/', $zip)) {
            return $zip;
        }
        return '';
    }

    /**
     * Geocode a UK outward postcode using the Freegle locations table.
     *
     * The MySQL `locations` table stores UK postcodes (type='Postcode') with
     * lat/lng coordinates. Querying by outward code prefix gives the geographic
     * centre of that postcode district — deterministic and free of Photon's
     * ambiguity for small/unusual UK place names.
     *
     * In development the table is empty (returns null). In production it is
     * populated by the Doogal postcode cron, so this works transparently.
     */
    protected function geocodePostcode(string $outward): ?array
    {
        // keep-raw: six differently-aliased aggregates (AVG/MIN/MAX x2) in one
        // SELECT list - the builder's aggregate methods (avg()/min()/max()) each
        // run and return a single value, there is no method that projects
        // several aliased aggregates together in one query.
        $row = DB::selectOne(
            "SELECT AVG(lat) AS lat, AVG(lng) AS lng,
                    MIN(lat) AS swlat, MIN(lng) AS swlng,
                    MAX(lat) AS nelat, MAX(lng) AS nelng
             FROM locations
             WHERE type = 'Postcode' AND name LIKE ? AND lat IS NOT NULL",
            [$outward . ' %']
        );

        if (!$row || $row->lat === null) {
            return null;
        }

        $swlat = (float) $row->swlat;
        $swlng = (float) $row->swlng;
        $nelat = (float) $row->nelat;
        $nelng = (float) $row->nelng;

        // If the district bbox is very small (single postcode), add a small margin
        if (($nelat - $swlat) < 0.001) {
            $swlat -= 0.005;
            $nelat += 0.005;
        }
        if (($nelng - $swlng) < 0.001) {
            $swlng -= 0.01;
            $nelng += 0.01;
        }

        return [$swlat, $swlng, $nelat, $nelng, $this->boxPoly($swlat, $swlng, $nelat, $nelng)];
    }

    protected function geocodeAddress(
        string $addr,
        bool $allowPoint,
        bool $exact,
        float $bbswlat = self::UK_SWLAT,
        float $bbswlng = self::UK_SWLNG,
        float $bbnelat = self::UK_NELAT,
        float $bbnelng = self::UK_NELNG,
        array $layers = []
    ): ?array {
        $addr = self::ADDRESS_FIXES[$addr] ?? $addr;

        $geocoderBase = config('freegle.geocoder');
        if (!$geocoderBase) {
            return null;
        }

        $url = rtrim($geocoderBase, '/') . '/api?q=' . urlencode($addr)
            . "&bbox=$bbswlng%2C$bbswlat%2C$bbnelng%2C$bbnelat";

        // Restrict to specific Photon layers (city/locality/district for a town
        // lookup) so a town name can't fuzzy-match a waterway ("Thame" → River
        // Thames, a 190km extent through London), a county ("Ham" → Hampshire) or
        // a landmark/company ("Bourne End" → Optoma Europe Ltd). Empty = no
        // restriction (state/region lookups, which Photon often types as "other").
        foreach ($layers as $layer) {
            $url .= '&layer=' . urlencode($layer);
        }

        // Photon rate-limits us with HTTP 429. Without the backoff retry,
        // a single burst poisons hundreds of (city,state,country) tuples
        // with cached nulls. Honour Retry-After if present; otherwise back
        // off with the suggested defaults (200ms, 800ms).
        $retryDelaysMs = [200, 800];
        $attempt       = 0;
        $results       = null;
        while (true) {
            try {
                $response = Http::timeout(10)->get($url);
            } catch (\Throwable) {
                return null;
            }
            $status = $response->status();
            if ($status === 429 && $attempt < count($retryDelaysMs)) {
                $retryAfter = $response->header('Retry-After');
                $delayMs    = is_numeric($retryAfter)
                    ? max(50, (int) $retryAfter * 1000)
                    : $retryDelaysMs[$attempt];
                usleep($delayMs * 1000);
                $attempt++;
                continue;
            }
            if (!$response->successful()) {
                return null;
            }
            $results = $response->json();
            break;
        }

        $features = $results['features'] ?? [];
        foreach ($features as $feature) {
            $props = $feature['properties'] ?? [];

            // Extent-based results are always usable as bbox hints.
            // Previously, an inverted nameMatches guard caused state lookups where
            // the geocoder name exactly matched (e.g. 'London' → 'London') to return
            // null, preventing state-constrained city searches for those regions.
            if (isset($props['extent'])) {
                // Photon extent order is [minLon, maxLat, maxLon, minLat]
                // (west, NORTH, east, SOUTH). Previously this was destructured as
                // [swlng, swlat, nelng, nelat], which put the NORTH edge into swlat
                // and the SOUTH edge into nelat — an upside-down bbox. That made the
                // area check go negative (so large regions like "East of England"
                // were treated as a tiny "specific location" and used directly,
                // skipping the city search) AND placed jobs at the wrong latitude —
                // e.g. East-of-England jobs landed at ~lat 51.5 (London). Map the
                // extent to the right corners.
                [$swlng, $nelat, $nelng, $swlat] = array_map('floatval', $props['extent']);
                return [$swlat, $swlng, $nelat, $nelng, $this->boxPoly($swlat, $swlng, $nelat, $nelng)];
            }

            if ($allowPoint) {
                $name        = $props['name'] ?? null;
                $nameMatches = $name && strcasecmp($name, $addr) === 0;
                if (!$exact || $nameMatches) {
                    $coords = $feature['geometry']['coordinates'] ?? null;
                    if ($coords) {
                        $lat   = (float) $coords[1];
                        $lng   = (float) $coords[0];
                        $swlng = $lng - 0.0005;
                        $swlat = $lat - 0.0005;
                        $nelat = $lat + 0.0005;
                        $nelng = $lng + 0.0005;
                        return [$swlat, $swlng, $nelat, $nelng, $this->boxPoly($swlat, $swlng, $nelat, $nelng)];
                    }
                }
                break;
            }
        }

        return null;
    }

    private function bboxFromWkt(string $wkt): ?array
    {
        // Extract all (lng lat) pairs from POLYGON WKT
        preg_match_all('/(-?\d+\.?\d*)\s+(-?\d+\.?\d*)/', $wkt, $m);
        if (count($m[1]) < 4) {
            return null;
        }
        $lngs = array_map('floatval', $m[1]);
        $lats = array_map('floatval', $m[2]);
        $swlat = min($lats);
        $swlng = min($lngs);
        $nelat = max($lats);
        $nelng = max($lngs);
        return [$swlat, $swlng, $nelat, $nelng, $this->boxPoly($swlat, $swlng, $nelat, $nelng)];
    }

    public static function boxPoly(float $swlat, float $swlng, float $nelat, float $nelng): string
    {
        return "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";
    }

    private function cleanBody(?string $text): ?string
    {
        if (!$text) {
            return null;
        }
        $body = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = str_replace(["\r\n", "\r", "\n", '–', 'Â'], [' ', ' ', ' ', '-', '-'], $body);
        $body = substr($body, 0, 256) . ' ';
        return $body;
    }

    /**
     * Fix the casing of an all-lowercase feed location for display.
     *
     * The WhatJobs feed supplies most locations entirely lowercase ("stockton
     * on tees", "poole, dorset" - ~80% of rows), which looks broken in job
     * ads. When the value contains no uppercase at all, title-case it UK-style:
     * every word capitalised except linking words ("Newcastle upon Tyne",
     * "Weston-super-Mare"), with apostrophes and dotted abbreviations left
     * intact ("Bishop's Stortford", "St. Columb"). A value with any existing
     * uppercase is the feed's own deliberate casing ("Dunham on the Hill") and
     * passes through untouched.
     */
    public function titleCaseLocation(?string $location): ?string
    {
        if ($location === null || $location === '' || $location !== mb_strtolower($location, 'UTF-8')) {
            return $location;
        }

        // Linking words stay lowercase mid-name, per UK place-name convention
        // ("Stockton-on-Tees", "Ashby-de-la-Zouch", "Chapel-en-le-Frith").
        $connectors = ['on', 'upon', 'under', 'by', 'in', 'of', 'the', 'and', 'at',
            'le', 'la', 'de', 'en', 'cum', 'super', 'next', 'over', 'with'];

        $first = true;

        return preg_replace_callback('/[^\s,\-\/]+/u', function ($m) use (&$first, $connectors) {
            $word = $m[0];
            if (!$first && in_array($word, $connectors, true)) {
                return $word;
            }
            $first = false;

            return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
        }, $location);
    }

    public function canonicalJobTitle(?string $title): ?string
    {
        if (empty($title)) {
            return null;
        }

        $clean = preg_replace('/\s+-\s+[A-Z][a-zA-Z\s&\']+$/', '', trim($title));
        $clean = strtolower(trim($clean));
        $clean = (string) preg_replace('/\s*\([^)]*\)\s*/', ' ', $clean);
        $clean = (string) preg_replace('/\s*-\s*ikea\s+\w+\s+store$/i', '', $clean);
        $clean = (string) preg_replace('/\s*-\s*[a-z]+,?\s+[a-z]+shire$/i', '', $clean);
        $clean = (string) preg_replace('/\s*-\s*haven$/i', '', $clean);
        $clean = (string) preg_replace('/\s*-\s*\w+\s+college\s*.*$/i', '', $clean);
        $clean = trim($clean);

        foreach (array_keys(self::CANONICAL_JOBS) as $canonical) {
            if (strtolower($canonical) === $clean) {
                return $canonical;
            }
        }

        foreach (self::JOB_KEYWORD_MAP as [$patterns, $canonical]) {
            $allMatch = true;
            foreach ($patterns as $keyword) {
                if (strpos($clean, strtolower($keyword)) === false) {
                    $allMatch = false;
                    break;
                }
            }
            if ($allMatch) {
                return $canonical;
            }
        }

        return null;
    }

    public function prepareTempTable(): void
    {
        Schema::dropIfExists('jobs_new');
        // keep-raw: CREATE TABLE ... LIKE clones the full structure (columns,
        // indexes, spatial SRID attribute) of the live `jobs` table verbatim.
        // Schema::create() has no equivalent; hand-redefining the columns would
        // fork from the jobs migration and silently drift out of sync with it.
        DB::statement('CREATE TABLE jobs_new LIKE jobs');
    }

    /**
     * Insert jobs into the jobs_new temp table, buffering BATCH_SIZE rows
     * between INSERTs so peak PHP memory is O(BATCH_SIZE), not O(all jobs).
     *
     * Accepts either an array (back-compat for direct callers and tests) or
     * any iterable — including the Generator returned by parseFeed(), which
     * is what lets sync() stream straight from XML into the DB without
     * holding ~180k job dicts in memory at once.
     */
    public function insertJobs(iterable $jobs, int $srid = 3857): int
    {
        // Preserve existing auto-increment IDs so email links (e.g. /jobs/12345) don't break
        $existingIds = DB::table('jobs')->pluck('id', 'job_reference')->all();

        // Clickability is a pure function of the title's keyword pairs weighted
        // by how often those pairs appear in *clicked* jobs (jobs_keywords,
        // rebuilt by analyseClickability() immediately before this call),
        // normalised by the 95th-percentile keyword count. Because it depends on
        // nothing but the title + those pre-computed frequencies, we compute it
        // here in PHP and set it on the INSERT. Previously a post-swap
        // updateClickability() pass issued one UPDATE per row — ~1M separate
        // autocommit statements, each its own Galera write-set certified and
        // applied synchronously across all three nodes. That sustained storm ran
        // for ~an hour and starved apiv2's connection pool (health checks hung,
        // monit restarted it). Folding it into the batched INSERT means the
        // freshly-swapped table is never touched again.
        $maxish   = $this->getMaxish();
        $keywords = [];
        foreach (DB::table('jobs_keywords')->select('keyword', 'count')->get() as $row) {
            $keywords[$row->keyword] = (int) $row->count;
        }

        $inserted = 0;
        $buffer   = [];

        $flush = function () use (&$buffer, &$inserted, $existingIds, $srid, $keywords, $maxish): void {
            if (empty($buffer)) {
                return;
            }

            $placeholders = [];
            $bindings     = [];

            foreach ($buffer as $j) {
                $id = $existingIds[$j['job_reference']] ?? null;

                $score = 0;
                foreach ($this->getKeywords($j['title']) as $kw) {
                    $score += $keywords[$kw] ?? 0;
                }
                $clickability = $maxish > 0 ? $score / $maxish : 0;

                $placeholders[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,ST_GeomFromText(?,?),?,?,?,?,?)';
                array_push(
                    $bindings,
                    $id,
                    $j['location'], $j['title'], $j['city'], $j['state'],
                    $j['zip'], $j['country'], $j['job_type'], $j['posted_at'],
                    $j['job_reference'], $j['company'], $j['category'], $j['url'],
                    $j['body'], $j['cpc'],
                    $j['geometry'], $srid,
                    $clickability, $j['bodyhash'], $j['seenat'],
                    $j['visible'], $j['canonical_title']
                );
            }

            $sql = 'INSERT IGNORE INTO jobs_new
                (id,location,title,city,state,zip,country,job_type,posted_at,
                 job_reference,company,category,url,body,cpc,geometry,
                 clickability,bodyhash,seenat,visible,canonical_title)
                VALUES ' . implode(',', $placeholders);

            // keep-raw: this is otherwise the "multi-row INSERT IGNORE with
            // hand-built placeholders -> insertOrIgnore(array)" pattern, but the
            // geometry column needs the ST_GeomFromText(?,?) spatial function
            // applied per row with real bound parameters (WKT + SRID, both
            // per-row data). insertOrIgnore()'s value array only binds plain
            // scalars/Expressions; an Expression column value (DB::raw(...))
            // contributes zero entries to the bindings array (see
            // Builder::cleanBindings(), which rejects Expression instances), so
            // a parameterised call embedded as an Expression would leave literal
            // "?" characters in the SQL with nothing bound to them. Embedding
            // the WKT/SRID as literals instead would mean hand-escaping
            // externally-sourced feed data into raw SQL - not safe.
            DB::statement($sql, $bindings);
            $inserted += count($buffer);
            $buffer = [];
        };

        foreach ($jobs as $job) {
            $buffer[] = $job;
            if (count($buffer) >= self::BATCH_SIZE) {
                $flush();
            }
        }
        $flush();

        return $inserted;
    }

    public function swapTables(): void
    {
        Schema::dropIfExists('jobs_old');
        // keep-raw: a single multi-table RENAME TABLE is atomic - both renames
        // take effect together, so `jobs` is never missing. Schema::rename()
        // only renames one table per call; doing this as two separate
        // Schema::rename() calls (jobs->jobs_old, then jobs_new->jobs) would
        // open a window where the `jobs` table doesn't exist at all, which
        // concurrently-running reads/serving code would hit.
        DB::statement('RENAME TABLE jobs TO jobs_old, jobs_new TO jobs');
        Schema::dropIfExists('jobs_old');
    }

    public function analyseClickability(): void
    {
        $cutoff = now()->subDays(31)->startOfDay()->format('Y-m-d H:i:s');
        DB::table('logs_jobs')->where('timestamp', '<', $cutoff)->delete();

        // Back-fill missing jobid from URL
        $logs = DB::table('logs_jobs')
            ->select('jobs.id as jobid', 'logs_jobs.id as lid')
            // Joined on URL, not id - that is the point: these are log rows
            // whose jobid was never resolved, matched back to the job by link.
            ->join('jobs', 'jobs.url', '=', 'logs_jobs.link')
            ->whereNull('logs_jobs.jobid')
            ->whereNotNull('logs_jobs.link')
            ->orderByDesc('logs_jobs.id')
            ->get()
            ->all();
        foreach ($logs as $log) {
            DB::table('logs_jobs')->where('id', $log->lid)->update(['jobid' => $log->jobid]);
        }

        // Rebuild keyword frequency from clicked jobs
        DB::table('jobs_keywords')->truncate();
        $clicked = DB::table('logs_jobs')
            ->distinct()
            ->select('jobs.title')
            ->join('jobs', 'logs_jobs.jobid', '=', 'jobs.id')
            ->get()
            ->all();
        foreach ($clicked as $row) {
            foreach ($this->getKeywords($row->title) as $keyword) {
                // keep-raw: ON DUPLICATE KEY UPDATE count = count + 1 is an
                // atomic increment. upsert() only emits `col = values(col)` or
                // `col = ?` (both a REPLACE of the stored value); converting
                // would discard the running count instead of incrementing it.
                DB::statement(
                    'INSERT INTO jobs_keywords (keyword, count) VALUES (?,1)
                     ON DUPLICATE KEY UPDATE count = count + 1',
                    [$keyword]
                );
            }
        }
    }

    public function updateClickability(): void
    {
        $maxish = $this->getMaxish();
        $keywords = [];
        foreach (DB::table('jobs_keywords')->select('keyword', 'count')->get() as $row) {
            $keywords[$row->keyword] = (int) $row->count;
        }

        $jobs = DB::table('jobs')->select('id', 'title')->get()->all();
        foreach ($jobs as $job) {
            $score = 0;
            foreach ($this->getKeywords($job->title) as $kw) {
                $score += $keywords[$kw] ?? 0;
            }
            $normalised = $maxish > 0 ? $score / $maxish : 0;
            DB::table('jobs')->where('id', $job->id)->update(['clickability' => $normalised]);
        }
    }

    private function getMaxish(): float
    {
        // keep-raw: emulates a window function (ROW_NUMBER() over count order)
        // via MySQL user-defined session variables (@row_num) to find the 95th
        // percentile count, then references that same session variable again in
        // the outer WHERE (ROUND(0.95 * @row_num)). The query builder has no
        // method for session variables, this cross-joined derived-table idiom,
        // or window functions.
        $rows = DB::select(
            'SELECT count FROM
             (SELECT t.*, @row_num := @row_num + 1 AS row_num
              FROM jobs_keywords t, (SELECT @row_num:=0) counter
              ORDER BY count) temp
             WHERE temp.row_num = ROUND(0.95 * @row_num)'
        );
        return $rows ? (float) $rows[0]->count : 1.0;
    }

    private function getKeywords(string $str): array
    {
        $words = array_values(array_filter(
            array_map(fn ($w) => preg_replace('/[^A-Za-z]/', '', $w), explode(' ', $str)),
            fn ($w) => strlen($w) > 2
        ));
        $pairs = [];
        for ($i = 0; $i < count($words) - 1; $i++) {
            $pairs[] = strtolower($words[$i]) . ' ' . strtolower($words[$i + 1]);
        }
        return $pairs;
    }
}
