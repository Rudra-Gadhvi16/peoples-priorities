<?php
// ============================================================================
// NIRDHAR - CITIZEN-DRIVEN CONSTITUENCY LEDGER PORTAL
// ============================================================================
// Zero-dependency single-file PHP solution. Detects API calls, serves JSON,
// connects to PDO SQLite db, creates schemas & seeds data automatically,
// and serves a stunning glassmorphic frontend for citizens and representatives.
// ============================================================================

// Error reporting config (Production ready)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set default timezone
date_default_timezone_set('Asia/Kolkata');

// Reference Data & Contexts
$WARDS = [
    "ward-1" => ["name" => "Ward 1", "lat" => 90, "lng" => 180, "population" => 8200],
    "ward-2" => ["name" => "Ward 2", "lat" => 190, "lng" => 200, "population" => 11400],
    "ward-4-7" => ["name" => "Ward 4-7", "lat" => 150, "lng" => 110, "population" => 26800],
    "ward-9" => ["name" => "Ward 9", "lat" => 250, "lng" => 150, "population" => 15100],
    "ward-11" => ["name" => "Ward 11", "lat" => 300, "lng" => 90, "population" => 9700],
];

$CONTEXT_DATA = [
    "education:ward-4-7" => [
        "enrollment_vs_capacity_pct" => 31,
        "avg_travel_distance_km" => 4.8,
        "plan_ref" => "EDU-04",
        "beneficiaries" => 2300,
    ],
    "infrastructure:ward-9" => [
        "recurring_seasons" => 3,
        "plan_ref" => "INF-11",
        "beneficiaries" => 5400,
    ],
    "skilling:ward-2" => [
        "nearest_centre_km" => 6.2,
        "working_age_population" => 9400,
        "plan_ref" => "SKL-02",
        "beneficiaries" => 800,
    ],
    "utilities:ward-11" => [
        "hamlet_clusters_unserved" => 1,
        "plan_ref" => "WTR-07",
        "beneficiaries" => 1200,
    ],
];

$DEVELOPMENT_PLAN_SIZE = 27;

$CATEGORY_KEYWORDS = [
    "education" => [
        "school", "shala", "shaala", "vidyalay", "classroom", "teacher",
        "enrollment", "toilet block", "girls toilet", "college", "padhai",
    ],
    "infrastructure" => [
        "road", "drain", "drainage", "flood", "pothole", "sadak", "naali",
        "bridge", "pul", "street", "footpath",
    ],
    "skilling" => [
        "vocational", "training", "skill", "iti", "job", "employment",
        "rojgar", "kaushal",
    ],
    "utilities" => [
        "water", "pani", "electricity", "bijli", "streetlight", "sewage",
        "supply", "tanker",
    ],
];

$LANGUAGE_HINTS = [
    "hi" => ["है", "में", "नहीं", "पानी", "स्कूल"],
    "mr" => ["आहे", "मध्ये", "शाळा", "पाणी"],
    "gu" => ["છે", "માં", "શાળા"],
    "ta" => ["உள்ளது", "பள்ளி"],
];

// Helper Functions
function classify_category($text) {
    global $CATEGORY_KEYWORDS;
    $lowered = mb_strtolower($text);
    $scores = [];
    foreach ($CATEGORY_KEYWORDS as $cat => $words) {
        $scores[$cat] = 0;
        foreach ($words as $w) {
            if (mb_strpos($lowered, $w) !== false) {
                $scores[$cat]++;
            }
        }
    }
    $best = 'general';
    $best_score = 0;
    foreach ($scores as $cat => $score) {
        if ($score > $best_score) {
            $best_score = $score;
            $best = $cat;
        }
    }
    return $best;
}

function detect_language($text, $declared) {
    global $LANGUAGE_HINTS;
    if (!empty($declared)) {
        return $declared;
    }
    foreach ($LANGUAGE_HINTS as $code => $hints) {
        foreach ($hints as $h) {
            if (mb_strpos($text, $h) !== false) {
                return $code;
            }
        }
    }
    return 'en';
}

function score_item($category, $ward_id, $mentions) {
    global $CONTEXT_DATA;
    $key = "$category:$ward_id";
    $ctx = isset($CONTEXT_DATA[$key]) ? $CONTEXT_DATA[$key] : [];
    
    $gap_raw = 0;
    if (isset($ctx['enrollment_vs_capacity_pct'])) {
        $gap_raw = $ctx['enrollment_vs_capacity_pct'];
    } elseif (isset($ctx['nearest_centre_km'])) {
        $gap_raw = $ctx['nearest_centre_km'];
    } elseif (isset($ctx['recurring_seasons'])) {
        $gap_raw = $ctx['recurring_seasons'];
    } elseif (isset($ctx['hamlet_clusters_unserved'])) {
        $gap_raw = $ctx['hamlet_clusters_unserved'];
    }

    $beneficiaries = isset($ctx['beneficiaries']) ? $ctx['beneficiaries'] : 0;

    $mention_score = min($mentions / 150.0, 1.0);
    $gap_score = min($gap_raw / 35.0, 1.0);
    $benef_score = min($beneficiaries / 3000.0, 1.0);

    $score = 0.55 * $mention_score + 0.30 * $gap_score + 0.15 * $benef_score;
    return [
        "score" => round($score * 100, 1),
        "context" => $ctx
    ];
}

// ---------------------------------------------------------------------------
// DB Initialization (Automatic Schema Setup)
// ---------------------------------------------------------------------------
$db_path = __DIR__ . '/nirdhar.db';

try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Create Tables if not exist
    $db->exec("CREATE TABLE IF NOT EXISTS submissions (
        id TEXT PRIMARY KEY,
        text TEXT NOT NULL,
        channel TEXT NOT NULL,
        language TEXT NOT NULL,
        ward_id TEXT,
        category TEXT NOT NULL,
        created_at REAL NOT NULL
    )");
    
    // Dynamic migration to add image_path column if missing
    $cols = $db->query("PRAGMA table_info(submissions)")->fetchAll();
    $has_image_path = false;
    foreach ($cols as $c) {
        if ($c['name'] === 'image_path') {
            $has_image_path = true;
            break;
        }
    }
    if (!$has_image_path) {
        $db->exec("ALTER TABLE submissions ADD COLUMN image_path TEXT DEFAULT NULL");
    }
    
    $db->exec("CREATE TABLE IF NOT EXISTS completed_projects (
        category TEXT NOT NULL,
        ward_id TEXT NOT NULL,
        completed_at REAL NOT NULL,
        actual_cost_lakhs REAL NOT NULL,
        satisfaction_rating INTEGER NOT NULL,
        review_text TEXT,
        PRIMARY KEY (category, ward_id)
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS escalations (
        category TEXT NOT NULL,
        ward_id TEXT NOT NULL,
        escalated_at REAL NOT NULL,
        PRIMARY KEY (category, ward_id)
    )");

    // Copy seed images to uploads directory
    $upload_dir = __DIR__ . '/uploads';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $seed_images = [
        'education' => 'problem_education.png',
        'infrastructure' => 'problem_infrastructure.png',
        'skilling' => 'problem_skilling.png',
        'utilities' => 'problem_utilities.png'
    ];
    foreach ($seed_images as $cat => $img_name) {
        $src = __DIR__ . '/' . $img_name;
        $dst = $upload_dir . '/' . $img_name;
        if (file_exists($src) && !file_exists($dst)) {
            copy($src, $dst);
        }
    }

    // Seed data if database is empty
    $count = $db->query("SELECT COUNT(*) c FROM submissions")->fetch()['c'];
    if ($count == 0) {
        $seed = [];
        for ($i = 0; $i < 142; $i++) { $seed[] = ["School capacity is overflowing, we need an extension", "ward-4-7"]; }
        for ($i = 0; $i < 88; $i++) { $seed[] = ["Drain overflow floods the road every monsoon", "ward-9"]; }
        for ($i = 0; $i < 61; $i++) { $seed[] = ["No vocational training centre nearby for our youth", "ward-2"]; }
        for ($i = 0; $i < 44; $i++) { $seed[] = ["Our hamlet still has no piped water connection", "ward-11"]; }
        for ($i = 0; $i < 31; $i++) { $seed[] = ["Streetlights have been down for months", "ward-1"]; }

        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO submissions (id, text, channel, language, ward_id, category, created_at, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $now = microtime(true);
        foreach ($seed as $item) {
            $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
            $category = classify_category($item[0]);
            $stmt->execute([
                $uuid,
                $item[0],
                'text',
                'en',
                $item[1],
                $category,
                $now - rand(0, 86400 * 5),
                null
            ]);
        }

        // Add seed photo grievances
        $photo_seeds = [
            ["Dilapidated school building structure with damaged walls.", "ward-4-7", "education", "uploads/problem_education.png"],
            ["Potholes and water logging detected on road.", "ward-9", "infrastructure", "uploads/problem_infrastructure.png"],
            ["No vocational training centre nearby for our youth.", "ward-2", "skilling", "uploads/problem_skilling.png"],
            ["Our hamlet still has no piped water connection.", "ward-11", "utilities", "uploads/problem_utilities.png"]
        ];
        foreach ($photo_seeds as $p_item) {
            $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
            $stmt->execute([
                $uuid,
                $p_item[0],
                'photo',
                'en',
                $p_item[1],
                $p_item[2],
                $now - rand(0, 86400 * 2),
                $p_item[3]
            ]);
        }
        $db->commit();
    }
} catch (PDOException $e) {
    die("Database Connection / Init Failed: " . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Unified Routing Controller
// ---------------------------------------------------------------------------
$request_uri = $_SERVER['REQUEST_URI'];
$path_info = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';

if (!$path_info) {
    $script_name = $_SERVER['SCRIPT_NAME'];
    $pos = strpos($request_uri, $script_name);
    if ($pos !== false) {
        $path_info = substr($request_uri, $pos + strlen($script_name));
    } else {
        $dir = dirname($script_name);
        if ($dir !== '/' && $dir !== '\\') {
            $pos = strpos($request_uri, $dir);
            if ($pos !== false) {
                $path_info = substr($request_uri, $pos + strlen($dir));
            }
        } else {
            $path_info = $request_uri;
        }
    }
}

// Strip query string
if (($pos = strpos($path_info, '?')) !== false) {
    $path_info = substr($path_info, 0, $pos);
}
$path_info = '/' . trim($path_info, '/');

// Handle API calls
if (strpos($path_info, '/api/') === 0 || isset($_GET['api'])) {
    header("Content-Type: application/json; charset=utf-8");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }

    if (isset($_GET['api'])) {
        $path_info = '/api/' . trim($_GET['api'], '/');
    }

    try {
        if ($path_info === '/api/submissions') {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true) ?: [];
                $text = isset($data['text']) ? trim($data['text']) : '';
                if (!$text && isset($data['channel']) && $data['channel'] === 'photo') {
                    $text = isset($data['caption']) ? trim($data['caption']) : '';
                    if (!$text) $text = 'Photo submission (no caption)';
                }
                if (!$text) {
                    http_response_code(400);
                    echo json_encode(['error' => 'text is required']);
                    exit;
                }
                $channel = isset($data['channel']) ? $data['channel'] : 'text';
                $ward_id = isset($data['ward_id']) ? $data['ward_id'] : null;
                $language = detect_language($text, isset($data['language']) ? $data['language'] : null);
                $category = classify_category($text);

                // Decode and save image if present
                $image_path = null;
                $image_b64 = isset($data['image']) ? $data['image'] : null;
                $image_name = isset($data['image_name']) ? $data['image_name'] : null;
                if ($image_b64 && $image_name) {
                    try {
                        if (strpos($image_b64, ',') !== false) {
                            list($header, $encoded) = explode(',', $image_b64, 2);
                        } else {
                            $header = '';
                            $encoded = $image_b64;
                        }
                        $decoded = base64_decode($encoded);
                        if ($decoded !== false) {
                            $ext = 'jpg';
                            if (strpos($header, 'png') !== false) {
                                $ext = 'png';
                            } elseif (strpos($header, 'gif') !== false) {
                                $ext = 'gif';
                            } elseif (strpos($header, 'webp') !== false) {
                                $ext = 'webp';
                            } else {
                                $ext = pathinfo($image_name, PATHINFO_EXTENSION) ?: 'jpg';
                            }
                            $upload_dir = __DIR__ . '/uploads';
                            if (!is_dir($upload_dir)) {
                                mkdir($upload_dir, 0755, true);
                            }
                            $filename = 'img_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
                            if (file_put_contents($upload_dir . '/' . $filename, $decoded)) {
                                $image_path = 'uploads/' . $filename;
                            }
                        }
                    } catch (Exception $e) {
                        // ignore error
                    }
                }

                $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
                
                $stmt = $db->prepare("INSERT INTO submissions (id, text, channel, language, ward_id, category, created_at, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$uuid, $text, $channel, $language, $ward_id, $category, microtime(true), $image_path]);

                http_response_code(201);
                echo json_encode([
                    'id' => $uuid,
                    'text' => $text,
                    'channel' => $channel,
                    'language' => $language,
                    'ward_id' => $ward_id,
                    'category' => $category,
                    'image_path' => $image_path,
                    'message' => 'Grievance submitted successfully. Registration ID: ' . $uuid
                ]);
                exit;
            } else {
                $ward = isset($_GET['ward_id']) ? $_GET['ward_id'] : null;
                $category = isset($_GET['category']) ? $_GET['category'] : null;
                $has_image = isset($_GET['has_image']) ? $_GET['has_image'] : null;

                $query = "SELECT * FROM submissions";
                $clauses = [];
                $params = [];
                if ($ward) {
                    $clauses[] = "ward_id = ?";
                    $params[] = $ward;
                }
                if ($category) {
                    $clauses[] = "category = ?";
                    $params[] = $category;
                }
                if ($has_image === 'true' || $has_image === '1') {
                    $clauses[] = "image_path IS NOT NULL AND image_path != ''";
                }
                if ($clauses) {
                    $query .= " WHERE " . implode(" AND ", $clauses);
                }
                $query .= " ORDER BY created_at DESC LIMIT 500";

                $stmt = $db->prepare($query);
                $stmt->execute($params);
                echo json_encode($stmt->fetchAll());
                exit;
            }
        }

        // Live Status Search Endpoint
        if ($path_info === '/api/submissions/status') {
            $id = isset($_GET['id']) ? trim($_GET['id']) : '';
            if (!$id) {
                http_response_code(400);
                echo json_encode(["error" => "Grievance ID is required"]);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM submissions WHERE id = ?");
            $stmt->execute([$id]);
            $sub = $stmt->fetch();
            
            if (!$sub) {
                http_response_code(404);
                echo json_encode(["error" => "Grievance registration number not found."]);
                exit;
            }

            $comp_stmt = $db->prepare("SELECT * FROM completed_projects WHERE category = ? AND ward_id = ?");
            $comp_stmt->execute([$sub['category'], $sub['ward_id']]);
            $comp = $comp_stmt->fetch();

            $esc_stmt = $db->prepare("SELECT 1 FROM escalations WHERE category = ? AND ward_id = ?");
            $esc_stmt->execute([$sub['category'], $sub['ward_id']]);
            $escalated = $esc_stmt->fetch() !== false;

            $ledger_stmt = $db->query(
                "SELECT ward_id, category, COUNT(*) c FROM submissions " .
                "WHERE ward_id IS NOT NULL AND category != 'general' " .
                "AND (category || ':' || ward_id) NOT IN (SELECT category || ':' || ward_id FROM completed_projects) " .
                "GROUP BY ward_id, category ORDER BY c DESC"
            );
            $rows = $ledger_stmt->fetchAll();

            $rank = -1;
            $items_count = count($rows);
            $demand_score = 0;
            foreach ($rows as $idx => $r) {
                if ($r['category'] === $sub['category'] && $r['ward_id'] === $sub['ward_id']) {
                    $rank = $idx + 1;
                    $scoring = score_item($sub['category'], $sub['ward_id'], (int)$r['c']);
                    $demand_score = $scoring['score'];
                    break;
                }
            }

            $status = 'Pending';
            if ($comp) {
                $status = 'Completed & Resolved';
            } elseif ($escalated) {
                $status = 'Escalated to Headquarters';
            }

            echo json_encode([
                "id" => $sub['id'],
                "text" => $sub['text'],
                "channel" => $sub['channel'],
                "language" => $sub['language'],
                "ward_id" => $sub['ward_id'],
                "ward_name" => isset($WARDS[$sub['ward_id']]) ? $WARDS[$sub['ward_id']]['name'] : $sub['ward_id'],
                "category" => $sub['category'],
                "created_at" => (float)$sub['created_at'],
                "image_path" => isset($sub['image_path']) ? $sub['image_path'] : null,
                "status" => $status,
                "rank" => $rank,
                "total_ledger_items" => $items_count,
                "demand_score" => $demand_score,
                "resolution" => $comp ? [
                    "completed_at" => (float)$comp['completed_at'],
                    "actual_cost_lakhs" => (float)$comp['actual_cost_lakhs'],
                    "satisfaction_rating" => (int)$comp['satisfaction_rating'],
                    "review_text" => $comp['review_text']
                ] : null
            ]);
            exit;
        }

        if ($path_info === '/api/stats') {
            $total = $db->query("SELECT COUNT(*) c FROM submissions")->fetch()['c'];
            $languages = $db->query("SELECT COUNT(DISTINCT language) c FROM submissions")->fetch()['c'];
            
            $hotspots = $db->query(
                "SELECT COUNT(DISTINCT ward_id) c FROM submissions " .
                "WHERE ward_id IS NOT NULL " .
                "AND (category || ':' || ward_id) NOT IN (SELECT category || ':' || ward_id FROM completed_projects)"
            )->fetch()['c'];

            $completed_stats = $db->query(
                "SELECT COUNT(*) count, COALESCE(SUM(actual_cost_lakhs), 0) total_cost, COALESCE(AVG(satisfaction_rating), 0) avg_rating FROM completed_projects"
            )->fetch();

            $completed_count = $completed_stats['count'];
            $utilized_budget = $completed_stats['total_cost'];
            $avg_rating = round($completed_stats['avg_rating'], 1);

            $total_budget_lakhs = 500.0;
            $remaining_budget = max($total_budget_lakhs - $utilized_budget, 0.0);

            echo json_encode([
                "total_submissions" => (int)$total,
                "languages" => (int)$languages,
                "active_hotspots" => (int)$hotspots,
                "competing_projects" => $DEVELOPMENT_PLAN_SIZE,
                "completed_count" => (int)$completed_count,
                "total_budget_lakhs" => $total_budget_lakhs,
                "utilized_budget_lakhs" => round($utilized_budget, 1),
                "remaining_budget_lakhs" => round($remaining_budget, 1),
                "average_satisfaction" => $avg_rating
            ]);
            exit;
        }

        if ($path_info === '/api/hotspots') {
            $stmt = $db->query(
                "SELECT ward_id, category, COUNT(*) c FROM submissions " .
                "WHERE ward_id IS NOT NULL " .
                "AND (category || ':' || ward_id) NOT IN (SELECT category || ':' || ward_id FROM completed_projects) " .
                "GROUP BY ward_id, category"
            );
            $rows = $stmt->fetchAll();

            $by_ward = [];
            foreach ($rows as $r) {
                $wid = $r['ward_id'];
                if (!isset($by_ward[$wid])) {
                    $by_ward[$wid] = array_merge(
                        ["ward_id" => $wid],
                        isset($WARDS[$wid]) ? $WARDS[$wid] : [],
                        [
                            "total_mentions" => 0,
                            "top_category" => null,
                            "categories" => []
                        ]
                    );
                }
                $by_ward[$wid]['categories'][$r['category']] = (int)$r['c'];
                $by_ward[$wid]['total_mentions'] += (int)$r['c'];
            }

            foreach ($by_ward as $wid => &$w) {
                arsort($w['categories']);
                $w['top_category'] = array_key_first($w['categories']);
            }

            echo json_encode(array_values($by_ward));
            exit;
        }

        if ($path_info === '/api/ledger') {
            $stmt = $db->query(
                "SELECT ward_id, category, COUNT(*) c FROM submissions " .
                "WHERE ward_id IS NOT NULL AND category != 'general' " .
                "AND (category || ':' || ward_id) NOT IN (SELECT category || ':' || ward_id FROM completed_projects) " .
                "GROUP BY ward_id, category ORDER BY c DESC"
            );
            $rows = $stmt->fetchAll();

            $items = [];
            foreach ($rows as $r) {
                $scoring = score_item($r['category'], $r['ward_id'], (int)$r['c']);

                $esc_stmt = $db->prepare("SELECT 1 FROM escalations WHERE category=? AND ward_id=?");
                $esc_stmt->execute([$r['category'], $r['ward_id']]);
                $escalated = $esc_stmt->fetch() !== false;

                $simulated_ages = [
                    "infrastructure:ward-9" => 6.2,
                    "education:ward-4-7" => 2.4,
                    "skilling:ward-2" => 1.1,
                    "utilities:ward-11" => 4.8,
                    "utilities:ward-1" => 5.5
                ];
                $key = $r['category'] . ':' . $r['ward_id'];
                $age = isset($simulated_ages[$key]) ? $simulated_ages[$key] : 0.5;

                $items[] = [
                    "ward_id" => $r['ward_id'],
                    "ward_name" => isset($WARDS[$r['ward_id']]) ? $WARDS[$r['ward_id']]['name'] : $r['ward_id'],
                    "category" => $r['category'],
                    "mentions" => (int)$r['c'],
                    "demand_score" => $scoring['score'],
                    "context" => $scoring['context'],
                    "age_days" => $age,
                    "escalated" => $escalated
                ];
            }

            usort($items, function($a, $b) {
                return $b['demand_score'] <=> $a['demand_score'];
            });

            foreach ($items as $idx => &$item) {
                $rank = $idx + 1;
                $item['rank'] = $rank;
                $item['rank_label'] = "§ $rank — Rank $rank of $DEVELOPMENT_PLAN_SIZE";
            }

            echo json_encode($items);
            exit;
        }

        if ($path_info === '/api/compare') {
            $a = isset($_GET['a']) ? $_GET['a'] : '';
            $b = isset($_GET['b']) ? $_GET['b'] : '';
            if (!$a || !$b) {
                http_response_code(400);
                echo json_encode(["error" => "provide a and b as category:ward_id"]);
                exit;
            }

            $build_item = function($spec) use ($db, $WARDS) {
                list($category, $ward_id) = explode(':', $spec);
                $stmt = $db->prepare("SELECT COUNT(*) c FROM submissions WHERE category=? AND ward_id=?");
                $stmt->execute([$category, $ward_id]);
                $c = (int)$stmt->fetch()['c'];
                $scoring = score_item($category, $ward_id, $c);
                return [
                    "category" => $category,
                    "ward_id" => $ward_id,
                    "ward_name" => isset($WARDS[$ward_id]) ? $WARDS[$ward_id]['name'] : $ward_id,
                    "mentions" => $c,
                    "demand_score" => $scoring['score'],
                    "context" => $scoring['context']
                ];
            };

            $item_a = $build_item($a);
            $item_b = $build_item($b);
            $winner = $item_a['demand_score'] >= $item_b['demand_score'] ? 'a' : 'b';

            echo json_encode(["a" => $item_a, "b" => $item_b, "recommended" => $winner]);
            exit;
        }

        if ($path_info === '/api/wards') {
            $res = [];
            foreach ($WARDS as $k => $v) {
                $res[] = array_merge(["ward_id" => $k], $v);
            }
            echo json_encode($res);
            exit;
        }

        if ($path_info === '/api/projects/complete') {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $category = isset($data['category']) ? $data['category'] : '';
            $ward_id = isset($data['ward_id']) ? $data['ward_id'] : '';
            $actual_cost = isset($data['actual_cost']) ? $data['actual_cost'] : null;
            $satisfaction = isset($data['satisfaction_rating']) ? $data['satisfaction_rating'] : null;
            $review = isset($data['review_text']) ? trim($data['review_text']) : '';

            if (!$category || !$ward_id) {
                http_response_code(400);
                echo json_encode(["error" => "category and ward_id are required"]);
                exit;
            }
            if ($actual_cost === null || $satisfaction === null || !is_numeric($actual_cost) || !is_numeric($satisfaction)) {
                http_response_code(400);
                echo json_encode(["error" => "actual_cost and satisfaction_rating must be numeric"]);
                exit;
            }

            $stmt = $db->prepare(
                "INSERT OR REPLACE INTO completed_projects (category, ward_id, completed_at, actual_cost_lakhs, satisfaction_rating, review_text) " .
                "VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$category, $ward_id, microtime(true), (float)$actual_cost, (int)$satisfaction, $review]);

            $del_stmt = $db->prepare("DELETE FROM escalations WHERE category=? AND ward_id=?");
            $del_stmt->execute([$category, $ward_id]);

            http_response_code(201);
            echo json_encode(["message" => "Project marked as completed and reviewed successfully."]);
            exit;
        }

        if ($path_info === '/api/projects/completed') {
            $stmt = $db->query("SELECT * FROM completed_projects ORDER BY completed_at DESC");
            $rows = $stmt->fetchAll();
            $items = [];
            foreach ($rows as $r) {
                $items[] = [
                    "category" => $r['category'],
                    "ward_id" => $r['ward_id'],
                    "ward_name" => isset($WARDS[$r['ward_id']]) ? $WARDS[$r['ward_id']]['name'] : $r['ward_id'],
                    "completed_at" => (float)$r['completed_at'],
                    "actual_cost_lakhs" => (float)$r['actual_cost_lakhs'],
                    "satisfaction_rating" => (int)$r['satisfaction_rating'],
                    "review_text" => $r['review_text']
                ];
            }
            echo json_encode($items);
            exit;
        }

        if ($path_info === '/api/projects/escalate') {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $category = isset($data['category']) ? $data['category'] : '';
            $ward_id = isset($data['ward_id']) ? $data['ward_id'] : '';

            if (!$category || !$ward_id) {
                http_response_code(400);
                echo json_encode(["error" => "category and ward_id are required"]);
                exit;
            }

            $stmt = $db->prepare("INSERT OR REPLACE INTO escalations (category, ward_id, escalated_at) VALUES (?, ?, ?)");
            $stmt->execute([$category, $ward_id, microtime(true)]);

            http_response_code(201);
            echo json_encode(["message" => "Project escalated to Headquarters successfully."]);
            exit;
        }

        if ($path_info === '/api/projects/escalations') {
            $stmt = $db->query("SELECT * FROM escalations ORDER BY escalated_at DESC");
            echo json_encode($stmt->fetchAll());
            exit;
        }

        http_response_code(404);
        echo json_encode(["error" => "API endpoint not found"]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nirdhar — Citizen Grievances & Constituency Priority Ledger</title>
<!-- Google Fonts: Outfit, Source Serif 4 & IBM Plex Mono -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;0,8..60,700;1,8..60,400&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="topbar">
  <div class="brand">
    <div class="mark">N</div>
    <div>
      <div class="word">Nirdhar</div>
      <div class="sub">Constituency Ledger & Grievances</div>
    </div>
  </div>
  <nav class="links" id="desktopNav">
    <a href="#home" class="active" onclick="navigateTo('#home', this)">Home</a>
    <a href="#citizen-services" onclick="navigateTo('#citizen-services', this)">Citizen Portal</a>
    <a href="#ledger" onclick="navigateTo('#ledger', this)">MP Command Center</a>
    <a href="#map" onclick="navigateTo('#map', this)">Hotspots Map</a>
  </nav>
  <div style="display:flex; align-items:center; gap:16px;">
    <button class="brief-btn" onclick="openModal('briefModal')">About Nirdhar ↗</button>
    
    <!-- Notification Bell Trigger & Dropdown -->
    <div style="position:relative;">
      <div class="notification-trigger" onclick="toggleNotificationDropdown(event)">
        <span class="bell-icon">🔔</span>
        <span class="badge" id="notificationBadge">2</span>
      </div>
      <div class="notification-dropdown" id="notificationDropdown">
        <div class="notification-dropdown-header">
          <span>Alerts & Notifications</span>
          <button onclick="markAllNotificationsRead(event)">Mark all as read</button>
        </div>
        <div id="notificationList">
          <!-- Populated by JS -->
        </div>
      </div>
    </div>

    <!-- MP Profile Avatar Trigger -->
    <div class="mp-profile-trigger" onclick="openModal('profileSettingsModal')">
      <div class="avatar">RP</div>
      <div class="info">
        <span class="name">Hon. R. Patil</span>
        <span class="role">MP Office</span>
      </div>
    </div>
  </div>
</header>

<!-- Mobile Bottom Navigation Bar -->
<div class="mobile-nav-bar" id="mobileNav">
  <div class="mobile-nav-item active" onclick="navigateTo('#home', this)">
    <span class="icon">🏠</span>
    <span>Home</span>
  </div>
  <div class="mobile-nav-item" onclick="navigateTo('#citizen-services', this)">
    <span class="icon">🔍</span>
    <span>Tracker</span>
  </div>
  <div class="mobile-nav-item" onclick="navigateTo('#ledger', this)">
    <span class="icon">🏛️</span>
    <span>MP Office</span>
  </div>
  <div class="mobile-nav-item" onclick="openModal('submitModal')">
    <span class="icon">✍️</span>
    <span>Lodge</span>
  </div>
</div>

<!-- HERO BANNER -->
<section class="hero" id="home">
  <div>
    <div class="eyebrow">Democratic priority ledger</div>
    <h1 class="headline">Every citizen concern,<br>mapped &amp; <em>prioritized</em><br>for development.</h1>
    <p class="lede">Nirdhar bridges the gap between scattered public grievances and structural developmental planning. We capture feedback via text, voice notes, and photos, classify them using keywords, and blend them with demographic context to ranks high-impact projects.</p>
    <div class="hero-actions">
      <button class="btn-primary" onclick="openModal('submitModal'); setMode(document.querySelectorAll('.mode-tab')[0], 'text')"><span style="font-size:18px;">+</span> Lodge Grievance</button>
      <button class="btn-ghost" onclick="focusTrackingWidget()">Track Submission Status</button>
    </div>
  </div>

  <!-- Hero panel status summary -->
  <div class="hero-panel" style="padding: 0; overflow: hidden; display: flex; flex-direction: column;">
    <div style="width: 100%; height: 150px; overflow: hidden; position: relative; border-bottom: 1px solid var(--card-border);">
      <img src="images/hero_illustration.png" alt="Digital India Map Data Visualization" style="width:100%; height:100%; object-fit:cover;">
      <div style="position: absolute; inset: 0; background: linear-gradient(to top, #090d16, transparent 95%);"></div>
      <div style="position: absolute; bottom: 12px; left: 18px; font-family:'IBM Plex Mono', monospace; font-size: 11px; text-transform: uppercase; letter-spacing: 0.1em; color: var(--accent-gold); font-weight:600; display:flex; align-items:center; gap:6px;">
        <span style="width:6px; height:6px; background:var(--accent-vermil); border-radius:50%; display:inline-block; animation:recPulse 1.5s infinite;"></span>
        📡 Nirdhar Command Triage
      </div>
    </div>
    <div style="padding: 20px 24px 24px;">
      <div class="label">Constituency submissions</div>
      <div class="counter-row">
        <div class="counter" id="statTotal">--</div>
        <div class="counter-note" id="statHotspotNote">across active wards</div>
      </div>
      <div class="lang-strip">
        <span class="lang-chip">हिन्दी</span>
        <span class="lang-chip">मराठी</span>
        <span class="lang-chip">English</span>
        <span class="lang-chip">ગુજરાતી</span>
        <span class="lang-chip">+ more</span>
      </div>
      <div class="channel-row">
        <div class="channel" style="cursor:pointer;" onclick="openModal('submitModal'); setMode(document.querySelectorAll('.mode-tab')[1], 'voice')"><span class="icon">🎙️</span>Voice</div>
        <div class="channel" style="cursor:pointer;" onclick="openModal('submitModal'); setMode(document.querySelectorAll('.mode-tab')[0], 'text')"><span class="icon">✍️</span>Text</div>
        <div class="channel" style="cursor:pointer;" onclick="openModal('submitModal'); setMode(document.querySelectorAll('.mode-tab')[2], 'photo')"><span class="icon">📷</span>Photo</div>
        <div class="channel" style="cursor:pointer;" onclick="openModal('submitModal'); setMode(document.querySelectorAll('.mode-tab')[0], 'text')"><span class="icon">💬</span>App</div>
      </div>
    </div>
  </div>
</section>

<!-- STATS STRIP -->
<section class="stats-strip">
  <div class="stat-box">
    <div class="num" id="statHighlightsSchool">142</div>
    <div class="cap">Mentions — School overflow in Ward 4-7</div>
  </div>
  <div class="stat-box">
    <div class="num">31%</div>
    <div class="cap">Capacity exceedance in local primary schools</div>
  </div>
  <div class="stat-box">
    <div class="num" id="statPlan">27</div>
    <div class="cap">Active projects in local development plan</div>
  </div>
  <div class="stat-box">
    <div class="num">6.2 km</div>
    <div class="cap">Median travel distance to nearest ITI skilling centre</div>
  </div>
</section>

<!-- CITIZEN PORTAL SECTION -->
<section class="citizen-section" id="citizen-services">
  <!-- Track Grievance Widget -->
  <div class="tracker-card" id="statusTrackerCard">
    <h3>🔍 Track Grievance Status</h3>
    <p class="card-desc">Enter the 36-character registration ID (UUID) provided during your grievance submission to check its current status, ledger rank, and resolution summary.</p>
    
    <div class="status-search-form">
      <input type="text" id="statusIdInput" class="status-search-input" placeholder="e.g. 5dc4a558-8687-41ab-8ee2-5e4a3d66cf77">
      <button class="btn-search-status" onclick="queryGrievanceStatus()">Track</button>
    </div>

    <!-- Status Search Results Box -->
    <div class="status-results-box" id="statusResultsBox">
      <div class="status-result-header">
        <strong style="font-size:14px; color:#fff;" id="statusResCategory">Grievance</strong>
        <span class="status-badge-val pending" id="statusResBadge">Pending</span>
      </div>
      
      <div class="status-detail-item"><span>Ward Area</span><strong id="statusResWard">--</strong></div>
      <div class="status-detail-item"><span>Priority Rank</span><strong id="statusResRank">--</strong></div>
      <div class="status-detail-item"><span>Priority Score</span><strong id="statusResScore">--</strong></div>
      <div class="status-detail-item"><span>Complaint Text</span><strong id="statusResSummary" style="max-width:24ch; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">--</strong></div>
      
      <!-- Related Picture Preview Row -->
      <div class="status-detail-item" id="statusResImageRow" style="display:none; flex-direction:column; align-items:flex-start; gap:8px; border:none; padding:12px 0 0 0; border-top:1.5px dashed rgba(255,255,255,0.06);">
        <span style="font-size:11px;">Related Picture:</span>
        <img id="statusResImage" src="" alt="Grievance Image" style="max-width:100%; max-height:220px; border-radius:var(--radius-sm); border:1px solid var(--card-border); object-fit:cover;">
      </div>

      <!-- Complete redressal report details if resolved -->
      <div id="statusResCompletedDetails" style="display:none; margin-top:14px; padding-top:12px; border-top:1.5px dashed rgba(255,255,255,0.06);">
        <div class="status-detail-item" style="color:var(--accent-sage);"><span>Actual Spent Cost</span><strong id="statusResActualCost">₹0.0 Lakhs</strong></div>
        <div class="status-detail-item" style="color:var(--accent-sage);"><span>Satisfaction Rating</span><strong id="statusResRating" style="color:var(--accent-gold);">★★★★★</strong></div>
        <div class="status-detail-item" style="flex-direction:column; align-items:flex-start; gap:4px; border:none; padding:4px 0 0 0;">
          <span style="font-size:11px;">Redressal Summary Report:</span>
          <p id="statusResReviewNotes" style="font-style:italic; font-size:12px; margin:0; color:var(--text-secondary);"></p>
        </div>
      </div>
    </div>
  </div>

  <!-- Citizen Problem Gallery -->
  <div>
    <div class="problems-gallery-title">
      <p>Constituency Demands</p>
      <h3>📸 Reported Field Concerns</h3>
    </div>
    <div class="gallery-grid">
      <div class="gallery-card" onclick="filterByCategory('education')">
        <img src="images/problem_education.png" alt="School overcrowding" loading="lazy">
        <div class="overlay-label">
          <span class="cat-badge education">Education</span>
          <div class="caption">School overcrowding — overflow details in Ward 4-7</div>
          <div class="ward-tag">Ward 4-7 · 142 mentions</div>
        </div>
      </div>
      <div class="gallery-card" onclick="filterByCategory('infrastructure')">
        <img src="images/problem_infrastructure.png" alt="Flooded road" loading="lazy">
        <div class="overlay-label">
          <span class="cat-badge infrastructure">Infrastructure</span>
          <div class="caption">Road flooding — blocked storm drainage in monsoon</div>
          <div class="ward-tag">Ward 9 · 88 mentions</div>
        </div>
      </div>
      <div class="gallery-card" onclick="filterByCategory('skilling')">
        <img src="images/problem_skilling.png" alt="Youth unemployment" loading="lazy">
        <div class="overlay-label">
          <span class="cat-badge skilling">Skilling</span>
          <div class="caption">Youth skilling centers — travel distances of 6km+</div>
          <div class="ward-tag">Ward 2 · 61 mentions</div>
        </div>
      </div>
      <div class="gallery-card" onclick="filterByCategory('utilities')">
        <img src="images/problem_utilities.png" alt="Water scarcity" loading="lazy">
        <div class="overlay-label">
          <span class="cat-badge utilities">Utilities</span>
          <div class="caption">Water tanker dependency — cluster connections lacking</div>
          <div class="ward-tag">Ward 11 · 44 mentions</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- SUCCESS STORIES GALLERY -->
<section class="success-gallery">
  <div class="section-head" style="padding: 0 0 8px 0;">
    <div class="section-title">✅ Completed Projects &amp; Success Stories</div>
    <div class="section-note">resolved &amp; verified deliveries</div>
  </div>
  <div class="success-grid">
    <div class="success-card">
      <img src="images/completed_school.png" alt="Renovated school" loading="lazy">
      <div class="tick">✓</div>
      <div class="success-label">
        <span class="success-badge">Education · Ward 4-7</span>
        <div class="success-caption">New classrooms built — student capacities expanded</div>
      </div>
    </div>
    <div class="success-card">
      <img src="images/completed_road.png" alt="Rehabilitated drainage" loading="lazy">
      <div class="tick">✓</div>
      <div class="success-label">
        <span class="success-badge">Infrastructure · Ward 9</span>
        <div class="success-caption">Drainage upgrade &amp; repaving completed</div>
      </div>
    </div>
    <div class="success-card">
      <img src="images/completed_water.png" alt="Piped water supply" loading="lazy">
      <div class="tick">✓</div>
      <div class="success-label">
        <span class="success-badge">Utilities · Ward 11</span>
        <div class="success-caption">Piped drinking water distribution network active</div>
      </div>
    </div>
  </div>
</section>

<!-- GROUND REALITY PHOTO STRIP -->
<section class="ground-reality">
  <div class="section-head" style="padding: 0 0 8px 0;">
    <div class="section-title">🤝 Citizen Participation</div>
    <div class="section-note">MP audits &amp; Gram Sabha hearings</div>
  </div>
  <div class="ground-strip">
    <div class="ground-card">
      <img src="images/mp_field_visit.png" alt="MP field visit" loading="lazy">
      <div class="ground-label">
        <span class="ground-tag">Field Inspection</span>
        <div class="ground-caption">Hon. Rajesh Patil auditing local drainage project progress</div>
        <div class="ground-sub">Ward 9 · Project Site Check · June 2026</div>
      </div>
    </div>
    <div class="ground-card">
      <img src="images/citizen_meeting.png" alt="Gram Sabha" loading="lazy">
      <div class="ground-label">
        <span class="ground-tag">Gram Sabha Hearing</span>
        <div class="ground-caption">Community dialogue discussing active ledger priorities</div>
        <div class="ground-sub">Ward 4-7 · Open Assembly · May 2026</div>
      </div>
    </div>
  </div>
</section>

<!-- DYNAMIC CITIZEN PHOTO FEED -->
<section class="ground-reality" id="citizen-photo-feed-section" style="display:none; padding: 56px clamp(16px, 4vw, 56px) 0; border-top: 1px solid var(--card-border);">
  <div class="section-head" style="padding: 0 0 8px 0;">
    <div class="section-title">📸 Citizen Ground Reality Feed</div>
    <div class="section-note">Live Photo Uploads from Citizens</div>
  </div>
  <div class="ground-strip" id="citizenPhotoStrip">
    <!-- Dynamic citizen concern photo cards go here -->
  </div>
</section>

<!-- MP LEDGER & COMMAND CENTER -->
<section class="ledger-dashboard-section" id="ledger">
  <div class="section-head">
    <div>
      <div class="section-title">🏛️ MPLAD Command Center</div>
      <span class="section-note">Representative Priority Ledger Dashboard</span>
    </div>
    <div class="section-note">Constituency: Wards 1 – 11</div>
  </div>

  <!-- MP overview banner -->
  <div class="mp-overview-banner">
    <div class="mp-meta-info">
      <div class="avatar-lg">RP</div>
      <div>
        <h4>Hon. Rajesh Patil</h4>
        <p>Constituency Member of Parliament Office</p>
      </div>
    </div>
    <div class="mp-budget-progress">
      <div class="mp-progress-labels">
        <span>MPLAD Budget Spent</span>
        <span id="profileBudgetPct">0%</span>
      </div>
      <div class="mp-progress-bar-track">
        <div class="mp-progress-bar-fill" id="profileBudgetBar"></div>
      </div>
      <div style="display:flex; justify-content:space-between; font-size:11px; color:var(--text-muted); margin-top:6px; font-family:'IBM Plex Mono', monospace;">
        <span>Spent: <strong id="profileSpentBudget" style="color:var(--accent-gold);">₹0.0L</strong></span>
        <span>Remaining: <strong id="profileBalanceBudget" style="color:var(--accent-blue);">₹500.0L</strong></span>
      </div>
    </div>
    <div class="mp-stats-row">
      <div class="mp-stat-item">
        <div class="val" id="profileActiveCount">--</div>
        <div class="lbl">Active Ledger</div>
      </div>
      <div class="mp-stat-item" style="border-left:1px solid var(--card-border); padding-left:16px;">
        <div class="val green" id="profileCompletedCount">0</div>
        <div class="lbl">Completed</div>
      </div>
      <div class="mp-stat-item" style="border-left:1px solid var(--card-border); padding-left:16px;">
        <div class="val" id="profileAvgRating" style="color:var(--accent-gold);">0.0</div>
        <div class="lbl">Avg Rating ★</div>
      </div>
    </div>
  </div>

  <!-- Dashboard Grid -->
  <div class="dashboard-grid">
    <!-- SVG Map & Comparison -->
    <div id="map">
      <div class="section-head" style="margin-bottom:14px;">
        <div class="section-title" style="font-size:18px;">🗺️ Active Hotspots Map</div>
        <div class="section-note">Interactive geographic hotspots</div>
      </div>
      <div class="map-card">
        <div class="map-wrapper">
          <svg class="map" viewBox="0 0 420 320" xmlns="http://www.w3.org/2000/svg">
            <rect x="0" y="0" width="420" height="320" rx="10" fill="#070b14"/>
            <!-- Constituency boundary outline -->
            <path class="map-path" d="M30 60 L120 40 L200 70 L260 50 L340 80 L390 120 L370 190 L300 230 L220 260 L140 250 L70 210 L40 150 Z"/>
            <g id="mapHotspots">
              <!-- Rendered dynamically -->
            </g>
          </svg>
        </div>
        <div class="map-legend">
          <span onclick="filterByCategory('education')"><i class="dot" style="background:var(--theme-education)"></i>Education</span>
          <span onclick="filterByCategory('infrastructure')"><i class="dot" style="background:var(--theme-infrastructure)"></i>Infrastructure</span>
          <span onclick="filterByCategory('skilling')"><i class="dot" style="background:var(--theme-skilling)"></i>Skilling</span>
          <span onclick="filterByCategory('utilities')"><i class="dot" style="background:var(--theme-utilities)"></i>Utilities</span>
        </div>
      </div>

      <!-- Compare Card -->
      <div class="compare-card" id="compare">
        <h3>⚖️ Head-to-Head Ledger Comparison</h3>
        <div class="desc">Select two categories/wards. Nirdhar will query ledger scores and display their relative priority side-by-side.</div>
        
        <div class="compare-controls">
          <select id="compareA" class="compare-select" onchange="runComparison()"></select>
          <span style="align-self: center; font-size: 13px; color: var(--text-muted); font-family: 'IBM Plex Mono', monospace;">VS</span>
          <select id="compareB" class="compare-select" onchange="runComparison()"></select>
        </div>

        <div class="compare-row" id="compareResult">
          <div class="compare-loader" id="compareLoader">
            <div class="spinner"></div>
          </div>
          <div class="compare-item" id="compCardA">
            <div>
              <div class="name" id="compNameA">Loading Proposal A...</div>
              <div class="score-val" id="compScoreA">--</div>
              <div class="metric">Citizen mentions <b id="compMentionsA">--</b></div>
              <div class="metric">Avg Travel / Gap <b id="compGapA">--</b></div>
              <div class="metric">Est. beneficiaries <b id="compBenefA">--</b></div>
            </div>
            <span class="lose-tag" id="compTagA">Comparison pending</span>
          </div>
          <div class="compare-item" id="compCardB">
            <div>
              <div class="name" id="compNameB">Loading Proposal B...</div>
              <div class="score-val" id="compScoreB">--</div>
              <div class="metric">Citizen mentions <b id="compMentionsB">--</b></div>
              <div class="metric">Avg Travel / Gap <b id="compGapB">--</b></div>
              <div class="metric">Est. beneficiaries <b id="compBenefB">--</b></div>
            </div>
            <span class="lose-tag" id="compTagB">Comparison pending</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Ranked ledger and completions archive -->
    <div>
      <div class="primary-ledger-tabs">
        <button class="ledger-view-tab active" id="btnActiveTab" onclick="switchLedgerTab('active')">Active Priorities</button>
        <button class="ledger-view-tab" id="btnCompletedTab" onclick="switchLedgerTab('completed')">Completed Archive</button>
      </div>

      <!-- ACTIVE LEDGER CONTENT WRAPPER -->
      <div id="activeLedgerWrapper">
        <div class="ledger-container">
          <div class="filter-bar">
            <div class="search-wrapper">
              <span class="search-icon">🔍</span>
              <input type="text" id="ledgerSearch" class="search-input" placeholder="Search description, category, plan ref..." oninput="filterLedger()">
            </div>
            <div class="filter-tabs">
              <button class="filter-tab active" onclick="setFilterTab(this, 'all')">All Themes</button>
              <button class="filter-tab" onclick="setFilterTab(this, 'education')">Education</button>
              <button class="filter-tab" onclick="setFilterTab(this, 'infrastructure')">Infrastructure</button>
              <button class="filter-tab" onclick="setFilterTab(this, 'skilling')">Skilling</button>
              <button class="filter-tab" onclick="setFilterTab(this, 'utilities')">Utilities</button>
            </div>
          </div>

          <div class="active-filter-badge" id="hotspotFilterBadge">
            <span>Filtering by Hotspot: <strong id="hotspotFilterName">Ward X</strong></span>
            <button onclick="clearHotspotFilter()">✕ Clear</button>
          </div>

          <div class="ledger-list" id="ledgerList">
            <div style="text-align: center; padding: 48px;">
              <div class="spinner" style="margin: 0 auto 16px;"></div>
              <span>Loading ledger list...</span>
            </div>
          </div>
        </div>
      </div>

      <!-- COMPLETED LEDGER CONTENT WRAPPER -->
      <div id="completedLedgerWrapper" style="display:none;">
        <div class="ledger-list" id="completedProjectsList">
          <div style="text-align: center; padding: 48px;">
            <div class="spinner" style="margin: 0 auto 16px;"></div>
            <span>Loading completed archive...</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- FLOATING CHATBOT -->
<div class="ai-chat-widget">
  <button class="ai-chat-toggle" onclick="toggleAIChat()">
    <span class="chat-icon">💬</span>
    <span>Ask Niru AI</span>
  </button>
  <div class="ai-chat-pane" id="aiChatPane">
    <div class="ai-chat-header">
      <h4>💬 Niru AI Assistant <span class="status"></span></h4>
      <button class="modal-close" style="width:24px; height:24px; font-size:12px; border:none; background:none; display:flex; align-items:center; justify-content:center;" onclick="toggleAIChat()">✕</button>
    </div>
    <div class="ai-chat-messages" id="aiChatMessages">
      <div class="chat-msg bot">
        Hello! I am Niru, your constituency assistant. I can help analyze your ledger data, draft escalations, or check budget status. Ask me anything!
      </div>
    </div>
    <div class="ai-chat-prompts">
      <span class="chat-prompt-chip" onclick="sendQuickPrompt('budget')">📊 Check Budget</span>
      <span class="chat-prompt-chip" onclick="sendQuickPrompt('priority')">🏆 Top Priority</span>
      <span class="chat-prompt-chip" onclick="sendQuickPrompt('sla')">🚨 SLA Breaches</span>
      <span class="chat-prompt-chip" onclick="sendQuickPrompt('draft')">✉️ Draft Escalation</span>
    </div>
    <div class="ai-chat-input-wrapper">
      <input type="text" id="aiChatInput" class="ai-chat-input" placeholder="Type a message..." onkeydown="handleChatKeyDown(event)">
      <button class="ai-chat-send" onclick="sendChatMessage()">➔</button>
    </div>
  </div>
</div>

<!-- SUBMIT SUGGESTION / GRIEVANCE MODAL -->
<div class="overlay" id="submitModal">
  <div class="modal">
    <div class="modal-head">
      <div>
        <h2>Lodge Public Grievance / Suggestion</h2>
        <p>Report local requirements or infrastructure needs. Text notes, audio recordings, or photo updates are supported.</p>
      </div>
      <button class="modal-close" onclick="closeModal('submitModal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="field-label">Preferred Language</div>
      <div class="lang-select" id="langSelect">
        <button class="lang-opt active" data-lang="en">English</button>
        <button class="lang-opt" data-lang="hi">हिन्दी</button>
        <button class="lang-opt" data-lang="mr">मराठी</button>
        <button class="lang-opt" data-lang="gu">ગુજરાતી</button>
        <button class="lang-opt" data-lang="ta">தமிழ்</button>
      </div>

      <div class="mode-tabs">
        <button class="mode-tab active" onclick="setMode(this,'text')"><span class="ic">✍️</span>Text Detail</button>
        <button class="mode-tab" onclick="setMode(this,'voice')"><span class="ic">🎙️</span>Voice Note</button>
        <button class="mode-tab" onclick="setMode(this,'photo')"><span class="ic">📷</span>Photo Upload</button>
      </div>

      <!-- TEXT MODE -->
      <div id="mode-text">
        <div class="field-label">Grievance Details</div>
        <textarea id="submitText" placeholder="Please describe the grievance or developmental requirements..."></textarea>
      </div>

      <!-- VOICE MODE -->
      <div id="mode-voice" style="display:none;">
        <div class="voice-box">
          <button class="rec-btn" id="voiceRecBtn" type="button" onclick="toggleRecording()">●</button>
          <div class="voice-status" id="voiceStatus">Click to Record</div>
          <div class="voice-timer" id="voiceTimer">00:00</div>
          <div class="wave-visualizer" id="waveVisualizer">
            <div class="wave-bar"></div><div class="wave-bar"></div><div class="wave-bar"></div>
            <div class="wave-bar"></div><div class="wave-bar"></div><div class="wave-bar"></div>
            <div class="wave-bar"></div>
          </div>
          <div class="voice-note" id="voiceHelpText">Speech recognition runs automatically.</div>
        </div>
      </div>

      <!-- PHOTO MODE -->
      <div id="mode-photo" style="display:none;">
        <input type="file" id="photoFileInput" accept="image/*" style="display:none;" onchange="handlePhotoUpload(event)">
        <div class="upload-box" onclick="document.getElementById('photoFileInput').click()">
          <span class="icon">📷</span>
          <strong>Click to select photo</strong>
          <p style="margin: 6px 0 0;">AI Vision classifications will automatically triage the theme.</p>
        </div>
        
        <div class="photo-preview-container" id="photoPreviewContainer">
          <img src="" class="photo-thumbnail" id="photoThumbnail" alt="preview">
          <div class="photo-meta">
            <div class="photo-name" id="photoName">image.jpg</div>
            <div class="photo-size" id="photoSize">0 KB</div>
            <button class="btn-remove-photo" onclick="removePhoto()">Remove photo</button>
          </div>
        </div>

        <div class="ai-analysis-banner" id="aiAnalysisBanner">
          <div class="spinner"></div>
          <span id="aiAnalysisText">Analyzing image with Niru-Vision AI...</span>
        </div>
      </div>

      <!-- Ward selection & prefill -->
      <div class="field-label" style="margin-top:20px; margin-bottom:0;">Ward Location</div>
      <div class="location-row">
        <select id="wardSelect">
          <option value="">Choose Constituency Ward...</option>
          <option value="ward-1">Ward 1 (Pop: 8.2k)</option>
          <option value="ward-2">Ward 2 (Pop: 11.4k)</option>
          <option value="ward-4-7">Ward 4-7 (Pop: 26.8k)</option>
          <option value="ward-9">Ward 9 (Pop: 15.1k)</option>
          <option value="ward-11">Ward 11 (Pop: 9.7k)</option>
        </select>
        <button type="button" onclick="useCurrentLocation()">📍 Geotag</button>
      </div>

      <button class="submit-btn" id="btnSubmitGrievance" onclick="submitGrievance()">Submit Grievance Suggestion</button>
      <div class="modal-foot-note" id="submitFootNote">All citizen demands are filed directly into Nirdhar priority ledger.</div>
    </div>
  </div>
</div>

<!-- MP PROFILE / SETTINGS MODAL -->
<div class="overlay" id="profileSettingsModal">
  <div class="modal" style="max-width: 480px;">
    <div class="modal-head">
      <div>
        <h2>MP Office Settings</h2>
        <p>Configure notifications and representatives profiles details.</p>
      </div>
      <button class="modal-close" onclick="closeModal('profileSettingsModal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="field-label">Representative Information</div>
      <div style="background:rgba(255,255,255,0.02); border:1px solid var(--card-border); border-radius:var(--radius-md); padding:16px; margin-bottom:20px;">
        <div style="font-weight:600; font-size:15px; margin-bottom:4px;">Rajesh Patil, MP</div>
        <div style="font-size:12px; color:var(--text-secondary);">District: Wards 1 – 11 Constituency</div>
      </div>

      <div style="margin-bottom:20px;">
        <div class="field-label">Link Phone Number (SMS Alerts)</div>
        <div class="status-search-form" style="margin-bottom:6px;">
          <input type="text" id="mpPhoneNumber" class="status-search-input" placeholder="e.g. +91 99887 76655" value="+91 99887 76655">
          <button class="btn-search-status" style="background:var(--accent-gold); color:var(--bg-main)" onclick="saveConnectedPhone()">Link</button>
        </div>
        <p id="phoneStatusMsg" style="font-size:11.5px; margin:4px 0 0 0; color:var(--text-muted);">SLA warnings trigger direct SMS integrations on the linked phone.</p>
      </div>

      <div style="border-top:1px solid var(--card-border); padding-top:20px; display:flex; justify-content:flex-end;">
        <button class="submit-btn" style="width:auto; margin-top:0;" onclick="closeModal('profileSettingsModal')">Save Settings</button>
      </div>
    </div>
  </div>
</div>

<!-- RESOLVE / COMPLETE PROJECT MODAL -->
<div class="overlay" id="completeProjectModal">
  <div class="modal" style="max-width: 480px;">
    <div class="modal-head">
      <div>
        <h2>✓ Resolve Grievance Priority</h2>
        <p>Complete project work and archive citizen redressal reports.</p>
      </div>
      <button class="modal-close" onclick="closeModal('completeProjectModal')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="completeProjectCategory">
      <input type="hidden" id="completeProjectWard">
      
      <div style="font-weight:600; font-size:15px; font-family:'Source Serif 4', serif; margin-bottom:18px;" id="completeProjectLabel">Project details</div>
      
      <div style="margin-bottom:18px;">
        <div class="field-label">Actual Spent Cost (Lakhs)</div>
        <input type="number" step="0.1" id="completeProjectCost" class="status-search-input" style="width:100%; border-radius:var(--radius-sm);" placeholder="e.g. 45.0">
      </div>

      <div style="margin-bottom:18px;">
        <div class="field-label">Citizen Satisfaction Rating</div>
        <div class="star-rating" id="completeProjectStars">
          <span class="star-btn active" onclick="setCompletionStarRating(1)">★</span>
          <span class="star-btn active" onclick="setCompletionStarRating(2)">★</span>
          <span class="star-btn active" onclick="setCompletionStarRating(3)">★</span>
          <span class="star-btn active" onclick="setCompletionStarRating(4)">★</span>
          <span class="star-btn active" onclick="setCompletionStarRating(5)">★</span>
        </div>
        <input type="hidden" id="completeProjectRating" value="5">
      </div>

      <div style="margin-bottom:20px;">
        <div class="field-label">Redressal Review Summary</div>
        <textarea id="completeProjectNotes" style="min-height:90px;" placeholder="Grievance resolved. Infrastructure inspected and verified..."></textarea>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
        <button class="submit-btn" style="background:rgba(255,255,255,0.03); border:1px solid var(--card-border); color:var(--text-primary); margin-top:0;" onclick="closeModal('completeProjectModal')">Cancel</button>
        <button class="submit-btn" style="background:var(--accent-sage); color:#fff; margin-top:0;" id="btnSaveCompletion" onclick="saveProjectCompletion()">✓ Save &amp; Resolve</button>
      </div>
    </div>
  </div>
</div>

<!-- HQ ESCALATION MODAL -->
<div class="overlay" id="escalateProjectModal">
  <div class="modal" style="max-width: 480px;">
    <div class="modal-head">
      <div>
        <h2>🚨 Escalate to Headquarters</h2>
        <p>This project has breached SLA limit of 5 days. Route directly to state offices.</p>
      </div>
      <button class="modal-close" onclick="closeModal('escalateProjectModal')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="escalateProjectCategory">
      <input type="hidden" id="escalateProjectWard">
      
      <div style="background:rgba(239, 68, 68, 0.05); border:1px solid rgba(239, 68, 68, 0.25); border-radius:var(--radius-md); padding:16px; margin-bottom:20px; color:var(--accent-vermil);">
        <div class="field-label" style="color:var(--accent-vermil); margin-bottom:4px;">SLA Breach Alert</div>
        <div style="font-weight:600; font-size:15px; font-family:'Source Serif 4', serif;" id="escalateProjectLabel">Project details</div>
        <div style="font-size:12px; font-family:'IBM Plex Mono', monospace; margin-top:6px;" id="escalateProjectAge">0 Days Pending (SLA: 5 Days Limit)</div>
      </div>

      <div class="location-row" style="flex-direction:column; gap:6px; margin:0 0 20px 0;">
        <div class="field-label" style="margin-bottom:0;">Recipient HQ Contact</div>
        <select id="hqContactSelect" onchange="updateEscalationWhatsAppLink()" style="width:100%; background:#0f1524; border:1px solid var(--card-border); color:var(--text-primary); border-radius:var(--radius-sm); padding:12px 14px;">
          <option value="+919988776655">District Chief Commissioner Office (+91 99887 76655)</option>
          <option value="+919123456789">State Development Authority (+91 91234 56789)</option>
          <option value="+919876543210">National Infrastructure Committee (+91 98765 43210)</option>
        </select>
      </div>

      <div style="margin-bottom:20px;">
        <div class="field-label">Escalation Message Summary</div>
        <textarea id="escalateProjectMessage" style="min-height:120px;" readonly></textarea>
      </div>

      <div style="display:flex; justify-content:center; margin-bottom:16px;">
        <a id="btnEscalateWhatsApp" href="#" target="_blank" class="chip" style="border-color:rgba(16, 185, 129, 0.4); color:var(--accent-sage); background:rgba(16, 185, 129, 0.05); display:inline-flex; align-items:center; gap:6px; font-weight:600; padding:8px 16px; border-radius:var(--radius-sm);">
          💬 Send via WhatsApp
        </a>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
        <button class="submit-btn" style="background:rgba(255,255,255,0.03); border:1px solid var(--card-border); color:var(--text-primary); margin-top:0;" onclick="closeModal('escalateProjectModal')">Cancel</button>
        <button class="submit-btn" style="background:var(--accent-vermil); color:#fff; margin-top:0;" id="btnSendEscalation" onclick="sendProjectEscalation()">🚨 Send Escalation Alert</button>
      </div>
    </div>
  </div>
</div>

<!-- ABOUT BRIEF MODAL -->
<div class="overlay" id="briefModal">
  <div class="modal">
    <div class="modal-head">
      <h2>About Nirdhar Priority Ledger</h2>
      <button class="modal-close" onclick="closeModal('briefModal')">✕</button>
    </div>
    <div class="modal-body brief-body">
      <p>Nirdhar is a citizen-submission-to-priority-ledger prototype application designed to bridge public concerns and state budget disbursements under the MPLAD program.</p>
      
      <h3>How it works</h3>
      <p>1. <strong>Collection:</strong> Citizens log grievances via audio notes, photographs, or text across regional languages.</p>
      <p>2. <strong>NLP &amp; Classification:</strong> Submissions are processed and sorted into developmental themes (Education, Infrastructure, Skilling, Utilities).</p>
      <p>3. <strong>Context Blending:</strong> Hotspots are merged with actual census databases (primary capacity gaps, school distance travel records, etc.).</p>
      <p>4. <strong>Ledger Calculation:</strong> A clear demand ranking formula weights citizen urgency (55%), capacity gap data (30%), and beneficiary targets (15%) to score projects on a 1-100 scale.</p>
    </div>
  </div>
</div>

<footer>
  <div style="max-width:1200px; margin: 0 auto; display:flex; justify-content:space-between; width:100%; flex-wrap:wrap; gap:12px;">
    <div>Nirdhar Priority Ledger Dashboard — SQLite Local Database.</div>
    <div>Designed &amp; Developed for Constituency developmental coordination.</div>
  </div>
</footer>

<script src="assets/js/main.js"></script>
</body>
</html>
