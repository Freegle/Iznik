<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JobIllustrationsService
{
    private const BATCH_SIZE = 5;

    /**
     * Canonical job categories mapped to their iconic object for image generation.
     * Kept in sync with iznik-server/include/misc/Pollinations.php CANONICAL_JOBS.
     */
    public const CANONICAL_JOBS = [
        'Accountant' => 'calculator',
        'Account Manager' => 'briefcase',
        'Activities Coordinator' => 'clipboard',
        'Administrator' => 'filing cabinet',
        'Architect' => 'blueprint',
        'Area Manager' => 'map with pins',
        'Assistant Manager' => 'name badge',
        'Bartender' => 'cocktail shaker',
        'Bid Manager' => 'sealed envelope',
        'Bookkeeper' => 'ledger book',
        'Branch Manager' => 'desk nameplate',
        'Bricklayer' => 'brick trowel',
        'Building Inspector' => 'spirit level',
        'Building Surveyor' => 'theodolite',
        'Bus Driver' => 'steering wheel',
        'Business Analyst' => 'flowchart diagram',
        'Business Development Manager' => 'handshake icon',
        'Buyer' => 'purchase order',
        'CAD Technician' => 'technical drawing',
        'Care Assistant' => 'stethoscope',
        'Care Coordinator' => 'care plan folder',
        'Care Worker' => 'medical gloves',
        'Carpenter' => 'wood plane',
        'Cashier' => 'cash register',
        'Catering Assistant' => 'serving tray',
        'Chef' => 'chef hat',
        'Cleaner' => 'mop and bucket',
        'Clinical Assessor' => 'medical clipboard',
        'CNC Machinist' => 'CNC milling machine',
        'Communications Engineer' => 'satellite dish',
        'Compliance Officer' => 'checklist on clipboard',
        'Construction Manager' => 'hard hat',
        'Contracts Manager' => 'signed contract',
        'Cook' => 'saucepan',
        'Counsellor' => 'comfortable armchair',
        'Credit Controller' => 'invoice stamp',
        'Customer Service Advisor' => 'headset',
        'Data Analyst' => 'bar chart',
        'Data Architect' => 'database server',
        'Data Engineer' => 'data pipeline diagram',
        'Delivery Driver' => 'delivery van',
        'Dental Nurse' => 'dental mirror',
        'Deputy Manager' => 'deputy badge',
        'Design Engineer' => 'engineering compass',
        'Design Manager' => 'design palette',
        'Digital Marketing Executive' => 'computer screen with analytics',
        'Document Controller' => 'document folder',
        'Door Canvasser' => 'clipboard with petition',
        'Ecologist' => 'binoculars',
        'Electrical Engineer' => 'circuit board',
        'Electrician' => 'wire strippers',
        'Embedded Software Engineer' => 'microchip',
        'Engineering Apprentice' => 'spanner set',
        'Estimator' => 'measuring tape and calculator',
        'Factory Operative' => 'conveyor belt',
        'Female Support Worker' => 'support badge',
        'Field Sales Representative' => 'sales sample case',
        'Field Service Engineer' => 'tool bag',
        'Finance Assistant' => 'spreadsheet printout',
        'Finance Business Partner' => 'financial report',
        'Finance Manager' => 'balance sheet',
        'Financial Controller' => 'accounting ledger',
        'Forklift Driver' => 'forklift',
        'Fundraiser' => 'collection tin',
        'Gas Engineer' => 'gas boiler',
        'General Manager' => 'office desk',
        'Groundworker' => 'shovel',
        'Head of Finance' => 'financial dashboard',
        'Head of Marketing' => 'megaphone',
        'Healthcare Assistant' => 'blood pressure monitor',
        'HGV Class 1 Driver' => 'articulated lorry',
        'HGV Class 2 Driver' => 'rigid lorry',
        'HGV Technician' => 'truck engine',
        'Home Manager' => 'care home building',
        'Housekeeper' => 'vacuum cleaner',
        'HR Advisor' => 'employee handbook',
        'HR Business Partner' => 'HR policy document',
        'Installer' => 'power drill',
        'IT Support' => 'computer keyboard',
        'IT Apprentice' => 'laptop computer',
        'Kitchen Assistant' => 'kitchen knife set',
        'Kitchen Designer' => 'kitchen floor plan',
        'Labourer' => 'wheelbarrow',
        'Lecturer' => 'lectern',
        'Legal Secretary' => 'legal documents',
        'Lifeguard' => 'lifeguard float',
        'Machine Learning Engineer' => 'neural network diagram',
        'Machine Operator' => 'industrial machine',
        'Maintenance Electrician' => 'multimeter',
        'Maintenance Engineer' => 'wrench and gears',
        'Maintenance Manager' => 'maintenance toolkit',
        'Maintenance Technician' => 'toolbox',
        'Management Accountant' => 'financial spreadsheet',
        'Manufacturing Engineer' => 'factory robot arm',
        'Marketing Manager' => 'marketing campaign board',
        'Maths Teacher' => 'protractor and compass',
        'Mechanical Design Engineer' => 'mechanical gear drawing',
        'Mechanical Engineer' => 'mechanical gears',
        'Mechanical Fitter' => 'pipe wrench',
        'Mechanic' => 'car jack',
        'Mobile Tyre Fitter' => 'tyre and wheel',
        'Mortgage Advisor' => 'house keys',
        'Multi Trade Operative' => 'multi-tool',
        'Nursery Manager' => 'toy building blocks',
        'Nursery Practitioner' => 'childrens storybook',
        'Nurse' => 'nurses cap',
        'Operations Manager' => 'operations dashboard',
        'Painter' => 'paint roller',
        'Parts Advisor' => 'car parts catalogue',
        'Passenger Assistant' => 'bus ticket machine',
        'Payroll Administrator' => 'payslip',
        'Payroll Specialist' => 'payroll software screen',
        'Personal Advisor' => 'advisory notepad',
        'Planning Officer' => 'town plan',
        'Plasterer' => 'plastering trowel',
        'Plumber' => 'pipe wrench and pipes',
        'Primary Teacher' => 'school bell',
        'Production Manager' => 'production line',
        'Production Operative' => 'assembly line component',
        'Production Supervisor' => 'quality control gauge',
        'Project Engineer' => 'project gantt chart',
        'Project Manager' => 'project plan board',
        'Property Manager' => 'set of property keys',
        'Quality Engineer' => 'caliper gauge',
        'Quality Inspector' => 'magnifying glass',
        'Quality Manager' => 'quality certificate',
        'Quantity Surveyor' => 'measuring tape and blueprints',
        'Reach Truck Driver' => 'reach truck',
        'Receptionist' => 'reception desk bell',
        'Recruitment Consultant' => 'CV document',
        'Refrigeration Engineer' => 'refrigeration unit',
        'Regional Sales Manager' => 'sales territory map',
        'Registered Manager' => 'care home registration certificate',
        'Research Associate' => 'microscope',
        'Residential Support Worker' => 'house key with lanyard',
        'Restaurant Team Member' => 'restaurant order pad',
        'Roofer' => 'roofing hammer',
        'Rough Sleeping Outreach Worker' => 'sleeping bag',
        'Sales Administrator' => 'sales order form',
        'Sales Advisor' => 'price tag',
        'Sales Consultant' => 'sales presentation',
        'Sales Engineer' => 'technical sales brochure',
        'Sales Executive' => 'business card',
        'Sales Manager' => 'sales trophy',
        'Sales Representative' => 'product sample kit',
        'Scaffolder' => 'scaffolding poles',
        'School Crossing Patrol' => 'lollipop stop sign',
        'Science Teacher' => 'laboratory flask',
        'Security Officer' => 'security badge',
        'SEN Teacher' => 'special education resource kit',
        'Senior Care Assistant' => 'medication trolley',
        'Service Advisor' => 'service desk terminal',
        'Service Engineer' => 'service toolbox',
        'Service Manager' => 'service level agreement',
        'Shift Engineer' => 'shift rota board',
        'Shift Leader' => 'team leader whistle',
        'Site Manager' => 'site plan',
        'Social Worker' => 'case file folder',
        'Software Engineer' => 'computer code on screen',
        'Solution Architect' => 'architecture diagram',
        'Store Manager' => 'retail shop front',
        'Structural Engineer' => 'structural beam drawing',
        'Supervisor' => 'supervisor clipboard',
        'Supply Teacher' => 'classroom whiteboard',
        'Support Worker' => 'support lanyard badge',
        'Teaching Assistant' => 'school exercise book',
        'Team Leader' => 'team whiteboard',
        'Technical Author' => 'technical manual',
        'Tiler' => 'tile cutter',
        'Transport Manager' => 'fleet management board',
        'Transport Planner' => 'route map',
        'Van Driver' => 'white van',
        'Vehicle Technician' => 'car diagnostic tool',
        'Warehouse Operative' => 'pallet of boxes',
        'Welder' => 'welding mask',
        'Window Installer' => 'window frame',
        'Workshop Controller' => 'workshop job board',
        'Workshop Technician' => 'workshop bench',
    ];

    public function __construct(private PollinationsService $pollinations) {}

    /**
     * Generate AI illustrations for canonical job categories that are missing images.
     *
     * @return array{processed: int, remaining: int}
     */
    public function processIllustrations(bool $dryRun = false): array
    {
        $processed = 0;
        $wouldFetch = 0;

        while (true) {
            $allNames = array_keys(self::CANONICAL_JOBS);
            $existing = DB::table('ai_images')
                ->whereIn('name', $allNames)
                ->pluck('name')
                ->toArray();

            $missing = array_diff($allNames, $existing);

            if (empty($missing)) {
                break;
            }

            $batch = array_slice($missing, 0, self::BATCH_SIZE);
            $batchItems = [];

            foreach ($batch as $title) {
                if ($this->pollinations->shouldSkipItem($title)) {
                    Log::info("JobIllustrations: skipping '{$title}' due to previous failures");
                    continue;
                }

                $object = self::CANONICAL_JOBS[$title];
                $batchItems[] = [
                    'name' => $title,
                    'prompt' => $this->pollinations->buildJobPrompt($object),
                    'width' => 200,
                    'height' => 200,
                    'msgid' => null,
                ];
            }

            if (empty($batchItems)) {
                break;
            }

            if ($dryRun) {
                // Don't call pollinations.ai (costs $); count and stop after reporting batch.
                $wouldFetch += count($batchItems);
                foreach ($batchItems as $item) {
                    Log::info("JobIllustrations dry-run: would fetch '{$item['name']}'");
                }
                break;
            }

            $batchResult = $this->pollinations->fetchBatch($batchItems, 120);

            if ($batchResult === false) {
                foreach ($batchItems as $item) {
                    $this->pollinations->recordFailure($item['name']);
                }
                Log::warning('JobIllustrations: batch rate-limited');
                break;
            }

            foreach ($batchResult['failed'] as $failedName => $dummy) {
                $this->pollinations->recordFailure($failedName);
            }

            foreach ($batchResult['results'] as $result) {
                $title = $result['name'];
                $imageData = $result['data'];
                $hash = $result['hash'];

                $uid = $this->pollinations->uploadImageAndCache($title, $imageData, $hash);
                if ($uid) {
                    $processed++;
                    Log::info("JobIllustrations: created illustration for '{$title}': {$uid}");
                }
            }

            if (empty($batchResult['results']) && empty($batchResult['failed'])) {
                break;
            }
        }

        $remaining = count(array_diff(
            array_keys(self::CANONICAL_JOBS),
            DB::table('ai_images')->whereIn('name', array_keys(self::CANONICAL_JOBS))->pluck('name')->toArray()
        ));

        return ['processed' => $processed, 'remaining' => $remaining, 'would_fetch' => $wouldFetch];
    }
}
