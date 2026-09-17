<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * The fictional businesses the demo is built from.
 *
 * Everything here is invented. The companies do not exist, the people do not
 * exist, and every address is under .example.test, which is reserved and can
 * never reach a real inbox. Nothing in this file may be replaced with a real
 * client, a real person or a real address.
 *
 * Customer codes all begin with BMP- because that prefix is what the demo
 * reset uses to recognise its own rows. A customer created by hand while
 * demonstrating will not carry it, and will not be swept away.
 *
 * Note on the schema: `customers` holds a name, a code and a status - there is
 * no industry, city or description column, and adding one to make the demo
 * prettier would be a schema change nobody asked for. The industry and the
 * town therefore live where the application already has room for them: in the
 * business name, in the org chart, and in the programme notes.
 */
final class DemoDataset
{
    public const CODE_PREFIX = 'BMP-';

    public const TEAM_DOMAIN = '@bmp.example.test';

    /**
     * @return list<array{
     *     code: string, name: string, industry: string, city: string,
     *     contact: array{name: string, role: string, email: string, phone: string},
     *     batch: string, turnover: string, headcount: int,
     *     constraint: string, objective: string
     * }>
     */
    public static function businesses(): array
    {
        return [
            [
                'code' => self::CODE_PREFIX.'APEX',
                'name' => 'Apex Precision Engineering Pvt. Ltd.',
                'industry' => 'Precision engineering and machining',
                'city' => 'Vadodara, Gujarat',
                'contact' => ['name' => 'Rajiv Mehta', 'role' => 'Managing Director', 'email' => 'rajiv@apexprecision.example.test', 'phone' => '+919824010001'],
                'batch' => 'BMP-VAD-A',
                'turnover' => '18.5 crore',
                'headcount' => 84,
                'constraint' => 'Quotation turnaround is slow because every enquiry waits on the Managing Director for costing.',
                'objective' => 'Grow to 25 crore without adding a second shift, by cutting quotation turnaround from 6 days to 2.',
            ],
            [
                'code' => self::CODE_PREFIX.'SHREEJI',
                'name' => 'Shreeji Industrial Components Pvt. Ltd.',
                'industry' => 'Industrial components manufacturing',
                'city' => 'Ahmedabad, Gujarat',
                'contact' => ['name' => 'Priya Shah', 'role' => 'Director', 'email' => 'priya@shreejicomponents.example.test', 'phone' => '+919824010002'],
                'batch' => 'BMP-AHM-A',
                'turnover' => '12.2 crore',
                'headcount' => 61,
                'constraint' => 'No documented process for enquiry handling, so follow-up depends on who remembers.',
                'objective' => 'Build a repeatable sales process and lift conversion from 18% to 30%.',
            ],
            [
                'code' => self::CODE_PREFIX.'VARDHAN',
                'name' => 'Vardhan Packaging Solutions',
                'industry' => 'Corrugated and flexible packaging',
                'city' => 'Rajkot, Gujarat',
                'contact' => ['name' => 'Amit Patel', 'role' => 'General Manager', 'email' => 'amit@vardhanpackaging.example.test', 'phone' => '+919824010003'],
                'batch' => 'BMP-VAD-A',
                'turnover' => '9.4 crore',
                'headcount' => 47,
                'constraint' => 'Production plan is made daily on a whiteboard and is out of date by mid-morning.',
                'objective' => 'Put daily production on a measured plan and reach 90% on-time dispatch.',
            ],
            [
                'code' => self::CODE_PREFIX.'MERIDIAN',
                'name' => 'Meridian Process Systems',
                'industry' => 'Process equipment and skids',
                'city' => 'Ankleshwar, Gujarat',
                'contact' => ['name' => 'Neha Desai', 'role' => 'Operations Head', 'email' => 'neha@meridianprocess.example.test', 'phone' => '+919824010004'],
                'batch' => 'BMP-AHM-A',
                'turnover' => '22.0 crore',
                'headcount' => 96,
                'constraint' => 'Project margins are known only after despatch, so loss-making jobs are found too late.',
                'objective' => 'Job-wise costing visible weekly, and gross margin held above 32%.',
            ],
            [
                'code' => self::CODE_PREFIX.'ARVIND',
                'name' => 'Arvind Electrical Systems',
                'industry' => 'Electrical panels and switchgear',
                'city' => 'Surat, Gujarat',
                'contact' => ['name' => 'Kunal Shah', 'role' => 'Chief Executive Officer', 'email' => 'kunal@arvindelectrical.example.test', 'phone' => '+919824010005'],
                'batch' => 'BMP-VAD-A',
                'turnover' => '15.8 crore',
                'headcount' => 72,
                'constraint' => 'The founder still approves every purchase, which stalls production when he travels.',
                'objective' => 'Delegate purchase approval under a defined limit and free two days a week.',
            ],
            [
                'code' => self::CODE_PREFIX.'ZENITH',
                'name' => 'Zenith Auto Components',
                'industry' => 'Automotive components',
                'city' => 'Vadodara, Gujarat',
                'contact' => ['name' => 'Rakesh Joshi', 'role' => 'Plant Head', 'email' => 'rakesh@zenithauto.example.test', 'phone' => '+919824010006'],
                'batch' => 'BMP-VAD-A',
                'turnover' => '31.5 crore',
                'headcount' => 140,
                'constraint' => 'Rejection rate at final inspection is 4.2% and nobody owns the number.',
                'objective' => 'Bring rejections below 1.5% and make quality a reviewed weekly KPI.',
            ],
            [
                'code' => self::CODE_PREFIX.'PRAKASH',
                'name' => 'Prakash Polymer Industries',
                'industry' => 'Injection moulding and polymers',
                'city' => 'Vapi, Gujarat',
                'contact' => ['name' => 'Sunita Rao', 'role' => 'Director — Operations', 'email' => 'sunita@prakashpolymer.example.test', 'phone' => '+919824010007'],
                'batch' => 'BMP-GROWTH-01',
                'turnover' => '7.6 crore',
                'headcount' => 38,
                'constraint' => 'Receivables run past 90 days because nobody follows up until cash is short.',
                'objective' => 'Bring average collection period from 88 days to 45.',
            ],
            [
                'code' => self::CODE_PREFIX.'WESTERN',
                'name' => 'Western Fabrication Works',
                'industry' => 'Heavy fabrication',
                'city' => 'Bharuch, Gujarat',
                'contact' => ['name' => 'Manish Trivedi', 'role' => 'Managing Partner', 'email' => 'manish@westernfab.example.test', 'phone' => '+919824010008'],
                'batch' => 'BMP-GROWTH-01',
                'turnover' => '11.1 crore',
                'headcount' => 55,
                'constraint' => 'Enquiries arrive on three different phones and are never written down in one place.',
                'objective' => 'One enquiry register, reviewed daily, and 20% more quotations issued.',
            ],
            [
                'code' => self::CODE_PREFIX.'SANKALP',
                'name' => 'Sankalp Tooling & Dies',
                'industry' => 'Tool room and die making',
                'city' => 'Rajkot, Gujarat',
                'contact' => ['name' => 'Bhavesh Vora', 'role' => 'Founder', 'email' => 'bhavesh@sankalptooling.example.test', 'phone' => '+919824010009'],
                'batch' => 'BMP-GROWTH-01',
                'turnover' => '6.2 crore',
                'headcount' => 29,
                'constraint' => 'Delivery promises are made without checking machine load.',
                'objective' => 'Load-based delivery commitments, and on-time delivery above 85%.',
            ],
            [
                'code' => self::CODE_PREFIX.'NIRMAN',
                'name' => 'Nirman Steel Structures Pvt. Ltd.',
                'industry' => 'Pre-engineered steel structures',
                'city' => 'Ahmedabad, Gujarat',
                'contact' => ['name' => 'Deepa Nair', 'role' => 'Business Head', 'email' => 'deepa@nirmansteel.example.test', 'phone' => '+919824010010'],
                'batch' => 'BMP-LEAD-02',
                'turnover' => '27.3 crore',
                'headcount' => 118,
                'constraint' => 'The second line of management has responsibility but no authority, so everything escalates.',
                'objective' => 'A working delegation matrix, with four decisions moved off the founder permanently.',
            ],
            [
                'code' => self::CODE_PREFIX.'GIRIRAJ',
                'name' => 'Giriraj Engineering Works',
                'industry' => 'General engineering and job work',
                'city' => 'Vadodara, Gujarat',
                'contact' => ['name' => 'Hitesh Parmar', 'role' => 'Proprietor', 'email' => 'hitesh@girirajengg.example.test', 'phone' => '+919824010011'],
                'batch' => 'BMP-LEAD-02',
                'turnover' => '4.8 crore',
                'headcount' => 22,
                'constraint' => 'One customer accounts for 61% of turnover.',
                'objective' => 'Bring the largest customer below 35% of turnover within four quarters.',
            ],
            [
                'code' => self::CODE_PREFIX.'SAMARTH',
                'name' => 'Samarth Hydraulics Pvt. Ltd.',
                'industry' => 'Hydraulic cylinders and power packs',
                'city' => 'Ankleshwar, Gujarat',
                'contact' => ['name' => 'Alpesh Chauhan', 'role' => 'Director', 'email' => 'alpesh@samarthhydraulics.example.test', 'phone' => '+919824010012'],
                'batch' => 'BMP-LEAD-02',
                'turnover' => '13.9 crore',
                'headcount' => 64,
                'constraint' => 'No management reporting: the first the team hears of a bad month is the balance sheet.',
                'objective' => 'A weekly management dashboard covering cash, enquiries, output and despatch.',
            ],
        ];
    }

    /**
     * The internal programme team. These are staff logins, not participants.
     *
     * @return list<array{name: string, email: string, role: string}>
     */
    public static function team(): array
    {
        return [
            ['name' => 'Anita Iyer', 'email' => 'anita.iyer'.self::TEAM_DOMAIN, 'role' => 'admin'],
            ['name' => 'Sanjay Bhatt', 'email' => 'sanjay.bhatt'.self::TEAM_DOMAIN, 'role' => 'staff'],
            ['name' => 'Farida Qureshi', 'email' => 'farida.qureshi'.self::TEAM_DOMAIN, 'role' => 'staff'],
        ];
    }

    /**
     * The six-session BMP curriculum.
     *
     * Titles carry no "Session N" prefix: the screens add the sequence
     * themselves, and repeating it here renders as "Session 3 — Session 3 —".
     *
     * @return list<array{sequence: int, title: string, theme: string, objectives: string}>
     */
    public static function curriculum(): array
    {
        return [
            [
                'sequence' => 1,
                'title' => 'Business Foundation',
                'theme' => 'Where the business actually stands today',
                'objectives' => "Establish the true current state: turnover, margin, headcount and the constraint that is actually holding the business back.\nAgree what will be measured for the rest of the programme.",
            ],
            [
                'sequence' => 2,
                'title' => 'Growth Strategy',
                'theme' => 'Choosing where growth will come from',
                'objectives' => "Separate the segments worth growing from the ones worth holding.\nSet a twelve-month objective the business can actually resource.",
            ],
            [
                'sequence' => 3,
                'title' => 'Sales & Business Development',
                'theme' => 'From enquiry to order, reliably',
                'objectives' => "Map the enquiry-to-order process end to end and find where it leaks.\nPut follow-up on a defined cadence instead of memory.",
            ],
            [
                'sequence' => 4,
                'title' => 'People & Leadership',
                'theme' => 'Delegation, accountability and the second line',
                'objectives' => "Build the org chart the business needs, not the one it has.\nMove named decisions off the founder with a delegation matrix.",
            ],
            [
                'sequence' => 5,
                'title' => 'Business Systems',
                'theme' => 'Making the business run without heroics',
                'objectives' => "Document the processes the business depends on.\nDecide what gets a system, what gets a checklist and what gets dropped.",
            ],
            [
                'sequence' => 6,
                'title' => 'Execution & Growth Plan',
                'theme' => 'The ninety days after the programme',
                'objectives' => "Convert the work into a dated ninety-day plan with named owners.\nAgree the review rhythm that keeps it alive.",
            ],
        ];
    }

    /**
     * Assignments, keyed by the session sequence they belong to.
     *
     * @return array<int, list<array{title: string, instructions: string, due_days: int}>>
     */
    public static function assignments(): array
    {
        return [
            1 => [[
                'title' => 'Define your 12-month business goal',
                'instructions' => 'State one measurable twelve-month objective for the business: the number, the date and how you will know you have reached it. One page.',
                'due_days' => 10,
            ]],
            2 => [[
                'title' => 'Identify your top 5 operational bottlenecks',
                'instructions' => 'List the five constraints that most limit output or growth today. For each, note who owns it and what it costs you per month.',
                'due_days' => 10,
            ]],
            3 => [[
                'title' => 'Document your sales process',
                'instructions' => 'Write the enquiry-to-order process as it actually runs today, step by step, with the person responsible at each step. Mark the steps with no owner.',
                'due_days' => 14,
            ]],
            4 => [[
                'title' => 'Create your delegation matrix',
                'instructions' => 'List the decisions you personally make in a week. For each, name who could make it, what they would need, and the limit you would set.',
                'due_days' => 14,
            ]],
            5 => [[
                'title' => 'Create a weekly KPI dashboard',
                'instructions' => 'Choose no more than eight numbers that tell you whether the week went well. State where each comes from and who publishes it.',
                'due_days' => 12,
            ]],
            6 => [[
                'title' => 'Create your 90-day growth plan',
                'instructions' => 'Convert the programme into a dated ninety-day plan: priorities, owners, target dates, and how each will be measured.',
                'due_days' => 21,
            ]],
        ];
    }

    /**
     * Batches, and the programme they run.
     *
     * @return list<array{code: string, name: string, starts: string, capacity: int, status: string}>
     */
    public static function batches(): array
    {
        return [
            ['code' => 'BMP-VAD-A', 'name' => 'BMP Vadodara Batch A', 'starts' => '-16 weeks', 'capacity' => 12, 'status' => 'running'],
            ['code' => 'BMP-AHM-A', 'name' => 'BMP Ahmedabad Batch A', 'starts' => '-10 weeks', 'capacity' => 12, 'status' => 'running'],
            ['code' => 'BMP-GROWTH-01', 'name' => 'BMP Growth Batch 01', 'starts' => '-4 weeks', 'capacity' => 10, 'status' => 'running'],
            ['code' => 'BMP-LEAD-02', 'name' => 'BMP Leadership Batch 02', 'starts' => '+3 weeks', 'capacity' => 10, 'status' => 'planned'],
        ];
    }
}
