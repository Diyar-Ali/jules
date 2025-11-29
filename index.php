<?php
// index.php

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database Configuration
const DB_FILE = 'database.sqlite';
const DAILY_CAP_MINUTES = 840;

function get_db() {
    try {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        // Return 500 error if DB connection fails
        http_response_code(500);
        echo json_encode(['error' => "Database connection failed: " . $e->getMessage()]);
        exit;
    }
}

function init_db() {
    $pdo = get_db();

    // Create skills table
    $pdo->exec("CREATE TABLE IF NOT EXISTS skills (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_ip TEXT NOT NULL,
        name TEXT NOT NULL,
        daily_goal INTEGER DEFAULT 60,
        total_minutes INTEGER DEFAULT 0,
        xp INTEGER DEFAULT 0,
        current_streak INTEGER DEFAULT 0,
        last_log_date TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_skills_ip ON skills(user_ip)");

    // Create logs table
    $pdo->exec("CREATE TABLE IF NOT EXISTS logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_ip TEXT NOT NULL,
        skill_id INTEGER NOT NULL,
        amount INTEGER NOT NULL,
        xp_gained INTEGER DEFAULT 0,
        is_crit INTEGER DEFAULT 0,
        date TEXT NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logs_ip_date ON logs(user_ip, date)");

    // Create user_stats table
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_stats (
        user_ip TEXT PRIMARY KEY,
        streak INTEGER DEFAULT 0,
        last_log_date TEXT
    )");
}

// Initialize Database
init_db();

// --- Helper Functions ---

function get_user_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function json_response($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function error_response($message, $code = 400) {
    http_response_code($code);
    json_response(['error' => $message]);
}

function get_json_input() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
    }
    return $input;
}

// XP Calculation Helpers
function calculate_diff($L) {
    return floor( ($L + 300 * pow(2, $L/7)) / 4 );
}

function get_xp_for_level($level) {
    $total_xp = 0;
    for ($i = 1; $i < $level; $i++) {
        $total_xp += calculate_diff($i);
    }
    return $total_xp;
}

function get_level_from_xp($xp) {
    $level = 1;
    while (true) {
        $xp_next = get_xp_for_level($level + 1);
        if ($xp < $xp_next) {
            return $level;
        }
        $level++;
        if ($level >= 99) return 99; // Cap at 99? Spec mentions 99 but not hard cap.
    }
}

// --- API Handlers ---

function handle_get_data($pdo, $ip) {
    // Fetch skills
    $stmt = $pdo->prepare("SELECT * FROM skills WHERE user_ip = ?");
    $stmt->execute([$ip]);
    $skills = $stmt->fetchAll();

    // Fetch today's logs
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM logs WHERE user_ip = ? AND date = ? ORDER BY timestamp DESC");
    $stmt->execute([$ip, $today]);
    $logs = $stmt->fetchAll();

    // Fetch user stats
    $stmt = $pdo->prepare("SELECT * FROM user_stats WHERE user_ip = ?");
    $stmt->execute([$ip]);
    $stats = $stmt->fetch();

    if (!$stats) {
        $stats = ['streak' => 0, 'last_log_date' => null];
    }

    // Determine current level for each skill
    foreach ($skills as &$skill) {
        $skill['level'] = get_level_from_xp($skill['xp']);
        $skill['next_level_xp'] = get_xp_for_level($skill['level'] + 1);
        $skill['current_level_xp'] = get_xp_for_level($skill['level']);
    }

    // Calculate total minutes today
    $minutes_today = 0;
    foreach ($logs as $log) {
        $minutes_today += $log['amount'];
    }

    json_response([
        'skills' => $skills,
        'logs' => $logs,
        'stats' => $stats,
        'minutes_today' => $minutes_today,
        'daily_cap' => DAILY_CAP_MINUTES
    ]);
}

function handle_add_skill($pdo, $ip) {
    $input = get_json_input();
    $name = trim($input['name'] ?? '');
    $goal = intval($input['goal'] ?? 60);

    if (empty($name)) {
        error_response("Skill name is required.");
    }

    $stmt = $pdo->prepare("INSERT INTO skills (user_ip, name, daily_goal) VALUES (?, ?, ?)");
    $stmt->execute([$ip, $name, $goal]);

    json_response(['success' => true, 'id' => $pdo->lastInsertId()]);
}

function handle_update_skill($pdo, $ip) {
    $input = get_json_input();
    $id = intval($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $goal = intval($input['goal'] ?? 60);

    if ($id <= 0 || empty($name)) {
        error_response("Invalid input.");
    }

    $stmt = $pdo->prepare("UPDATE skills SET name = ?, daily_goal = ? WHERE id = ? AND user_ip = ?");
    $stmt->execute([$name, $goal, $id, $ip]);

    json_response(['success' => true]);
}

function handle_delete_skill($pdo, $ip) {
    $input = get_json_input();
    $id = intval($input['id'] ?? 0);

    if ($id <= 0) {
        error_response("Invalid skill ID.");
    }

    // Verify ownership
    $stmt = $pdo->prepare("DELETE FROM skills WHERE id = ? AND user_ip = ?");
    $stmt->execute([$id, $ip]);

    // Also delete logs? Spec says "Irreversibly removes a skill and its history."
    $stmt = $pdo->prepare("DELETE FROM logs WHERE skill_id = ? AND user_ip = ?");
    $stmt->execute([$id, $ip]);

    json_response(['success' => true]);
}

function handle_allocate($pdo, $ip) {
    $input = get_json_input();
    $skill_id = intval($input['id'] ?? 0);
    $amount = intval($input['amount'] ?? 0);

    if ($skill_id <= 0 || $amount == 0) {
        error_response("Invalid input.");
    }

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    try {
        $pdo->beginTransaction();

        // 1. Verify Skill Ownership
        $stmt = $pdo->prepare("SELECT * FROM skills WHERE id = ? AND user_ip = ?");
        $stmt->execute([$skill_id, $ip]);
        $skill = $stmt->fetch();

        if (!$skill) {
            throw new Exception("Skill not found.");
        }

        // 2. Check Daily Cap (only for positive allocations)
        if ($amount > 0) {
            $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM logs WHERE user_ip = ? AND date = ?");
            $stmt->execute([$ip, $today]);
            $current_usage = intval($stmt->fetch()['total'] ?? 0);

            if ($current_usage + $amount > DAILY_CAP_MINUTES) {
                throw new Exception("Daily time budget exceeded.");
            }
        }

        // 3. Calculate XP & Crit
        $xp_gained = 0;
        $is_crit = 0;

        if ($amount > 0) {
            $base_xp_rate = 20;
            $xp_gained = $amount * $base_xp_rate;

            // 5% chance for crit
            if (rand(1, 100) <= 5) {
                $is_crit = 1;
                $xp_gained *= 5; // 5x Multiplier
            }
        } else {
            // Negative allocation (correction)
            // Reduce XP proportionally? Spec says: "Reduce total_minutes and xp in skills table."
            // "Do not trigger Critical XP on negative input."
            // Assuming we just reverse base XP? Or should we try to reverse exactly what was added?
            // "Constraint: Do not alter streaks on negative input."
            // Simple approach: Reverse base XP (20 XP/min). It's hard to know if the removed minutes were crit or not without targeting a specific log.
            // I'll assume standard rate reversal to keep it simple, or 0 if we don't want to penalize heavily.
            // Spec says "Reduce total_minutes and xp".
            // Let's assume standard rate.
            $xp_gained = $amount * 20;
        }

        // 4. Update Skill
        // Streak Logic:
        // If last_log_date == yesterday: Increment Streak.
        // If last_log_date == today: Maintain Streak.
        // Else: Reset Streak to 1.

        // Scope: Tracked individually per Skill and globally per User IP.

        $new_skill_streak = $skill['current_streak'];
        $skill_last_log = $skill['last_log_date'];

        if ($amount > 0) {
             if ($skill_last_log === $yesterday) {
                $new_skill_streak++;
            } elseif ($skill_last_log !== $today) {
                $new_skill_streak = 1;
            }
             // If today, maintain.

             // Update last log date only on positive input
             $skill_last_log = $today;
        }

        // Update Skill DB
        $stmt = $pdo->prepare("UPDATE skills SET total_minutes = total_minutes + ?, xp = xp + ?, current_streak = ?, last_log_date = ? WHERE id = ?");
        // Ensure XP doesn't go below 0? Spec doesn't say. SQLite handles integers.
        $stmt->execute([$amount, $xp_gained, $new_skill_streak, $skill_last_log, $skill_id]);


        // 5. Insert Log
        $stmt = $pdo->prepare("INSERT INTO logs (user_ip, skill_id, amount, xp_gained, is_crit, date) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$ip, $skill_id, $amount, $xp_gained, $is_crit, $today]);

        // 6. Update User Stats
        $stmt = $pdo->prepare("SELECT * FROM user_stats WHERE user_ip = ?");
        $stmt->execute([$ip]);
        $user_stats = $stmt->fetch();

        $new_user_streak = $user_stats['streak'] ?? 0;
        $user_last_log = $user_stats['last_log_date'] ?? null;

        if ($amount > 0) {
            if ($user_last_log === $yesterday) {
                $new_user_streak++;
            } elseif ($user_last_log !== $today) {
                $new_user_streak = 1;
            }
             // If today, maintain.

             // Update User Stats
            if ($user_stats) {
                $stmt = $pdo->prepare("UPDATE user_stats SET streak = ?, last_log_date = ? WHERE user_ip = ?");
                $stmt->execute([$new_user_streak, $today, $ip]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO user_stats (user_ip, streak, last_log_date) VALUES (?, ?, ?)");
                $stmt->execute([$ip, $new_user_streak, $today]);
            }
        }


        $pdo->commit();

        // Calculate new total minutes for response
        $stmt = $pdo->prepare("SELECT total_minutes FROM skills WHERE id = ?");
        $stmt->execute([$skill_id]);
        $new_minutes = $stmt->fetchColumn();

        json_response([
            'success' => true,
            'crit' => (bool)$is_crit,
            'xp' => $xp_gained,
            'new_minutes' => $new_minutes
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_response($e->getMessage());
    }
}


// --- Main Router ---

$api = $_GET['api'] ?? null;
$pdo = get_db();
$ip = get_user_ip();

if ($api) {
    switch ($api) {
        case 'get_data':
            handle_get_data($pdo, $ip);
            break;
        case 'add_skill':
            handle_add_skill($pdo, $ip);
            break;
        case 'update_skill':
            handle_update_skill($pdo, $ip);
            break;
        case 'delete_skill':
            handle_delete_skill($pdo, $ip);
            break;
        case 'allocate':
            handle_allocate($pdo, $ip);
            break;
        default:
            error_response("Invalid endpoint.", 404);
    }
    exit;
}

// --- Frontend Serving ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chronos Skill Tracker</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom animations for toast */
        @keyframes slideIn {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        .toast-enter {
            animation: slideIn 0.3s ease-out forwards;
        }
        .toast-exit {
            animation: fadeOut 0.3s ease-in forwards;
        }

        /* Progress Bar Transition */
        .progress-bar {
            transition: width 0.5s ease-in-out;
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen font-sans flex flex-col">
    <div id="app" class="container mx-auto p-4 flex-grow flex flex-col gap-6">
        <!-- Header & Timer -->
        <header class="flex flex-col md:flex-row justify-between items-center gap-4 border-b border-slate-700 pb-4">
            <div>
                <h1 class="text-4xl font-bold tracking-tighter text-indigo-400">CHRONOS</h1>
                <p class="text-sm text-slate-400">Gamified Time Tracking Protocol</p>
            </div>

            <div class="text-right flex flex-col items-center md:items-end">
                <div id="timer-display" class="text-5xl font-mono font-bold text-emerald-400">--:--</div>
                <div class="text-xs text-slate-500 uppercase tracking-widest mt-1">Daily Budget Remaining</div>
                <div id="tier-badge" class="mt-2 px-3 py-1 rounded-full text-xs font-bold bg-slate-800 text-slate-400">
                    CALCULATING...
                </div>
            </div>
        </header>

        <!-- Stats Overview -->
        <section class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-slate-800 p-4 rounded-lg border border-slate-700">
                <div class="text-slate-400 text-xs uppercase">Daily Streak</div>
                <div id="global-streak" class="text-2xl font-bold text-orange-400">0</div>
            </div>
             <div class="bg-slate-800 p-4 rounded-lg border border-slate-700">
                <div class="text-slate-400 text-xs uppercase">Today's Minutes</div>
                <div id="total-minutes-today" class="text-2xl font-bold text-blue-400">0</div>
            </div>
             <div class="bg-slate-800 p-4 rounded-lg border border-slate-700">
                <div class="text-slate-400 text-xs uppercase">XP Gained Today</div>
                <div id="xp-today" class="text-2xl font-bold text-purple-400">0</div>
            </div>
             <div class="bg-slate-800 p-4 rounded-lg border border-slate-700">
                <div class="text-slate-400 text-xs uppercase">Active Skills</div>
                <div id="active-skills-count" class="text-2xl font-bold text-slate-200">0</div>
            </div>
        </section>

        <!-- Skills Grid -->
        <main class="flex-grow">
             <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold">Active Protocols</h2>
                <button onclick="openModal('add-skill-modal')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded text-sm font-medium transition-colors">
                    + New Skill
                </button>
            </div>

            <div id="skills-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Skills will be injected here -->
                <div class="text-slate-500 italic text-center col-span-full py-8">Loading protocols...</div>
            </div>
        </main>

        <footer class="text-center text-slate-600 text-xs py-4 border-t border-slate-800">
            CHRONOS v1.0 &bull; IP: <?php echo htmlspecialchars($ip); ?>
        </footer>
    </div>

    <!-- Add/Edit Skill Modal -->
    <div id="add-skill-modal" class="fixed inset-0 bg-black/80 hidden flex items-center justify-center z-50">
        <div class="bg-slate-800 p-6 rounded-lg w-full max-w-md border border-slate-700 shadow-2xl">
            <h3 id="modal-title" class="text-xl font-bold mb-4 text-white">Initialize Protocol</h3>
            <form id="skill-form" onsubmit="handleSkillSubmit(event)">
                <input type="hidden" id="skill-id">
                <div class="mb-4">
                    <label class="block text-slate-400 text-sm mb-2">Protocol Name</label>
                    <input type="text" id="skill-name" class="w-full bg-slate-900 border border-slate-600 rounded px-3 py-2 text-white focus:outline-none focus:border-indigo-500" required>
                </div>
                <div class="mb-6">
                    <label class="block text-slate-400 text-sm mb-2">Daily Goal (Minutes)</label>
                    <input type="number" id="skill-goal" value="60" class="w-full bg-slate-900 border border-slate-600 rounded px-3 py-2 text-white focus:outline-none focus:border-indigo-500" required>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeModal('add-skill-modal')" class="text-slate-400 hover:text-white px-4 py-2">Cancel</button>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded font-medium">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Allocation Modal -->
    <div id="allocate-modal" class="fixed inset-0 bg-black/80 hidden flex items-center justify-center z-50">
        <div class="bg-slate-800 p-6 rounded-lg w-full max-w-sm border border-slate-700 shadow-2xl text-center">
            <h3 class="text-xl font-bold mb-2 text-white" id="allocate-skill-name">Skill Name</h3>
            <p class="text-slate-400 text-sm mb-6">Invest time to generate XP.</p>

            <form id="allocate-form" onsubmit="handleAllocate(event)">
                <input type="hidden" id="allocate-skill-id">
                <div class="flex items-center justify-center gap-4 mb-6">
                     <button type="button" onclick="adjustAmount(-15)" class="w-10 h-10 rounded-full bg-slate-700 hover:bg-slate-600 text-white flex items-center justify-center font-bold">-15</button>
                     <input type="number" id="allocate-amount" value="30" class="w-24 bg-slate-900 border border-slate-600 rounded px-3 py-2 text-center text-xl font-bold text-white focus:outline-none focus:border-indigo-500">
                     <button type="button" onclick="adjustAmount(15)" class="w-10 h-10 rounded-full bg-slate-700 hover:bg-slate-600 text-white flex items-center justify-center font-bold">+15</button>
                </div>
                <div class="flex justify-center gap-3">
                    <button type="button" onclick="closeModal('allocate-modal')" class="text-slate-400 hover:text-white px-4 py-2">Cancel</button>
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-8 py-2 rounded font-bold tracking-wide">COMMIT</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="fixed bottom-4 right-4 flex flex-col gap-2 z-50"></div>

    <script src="app.js"></script>
</body>
</html>
