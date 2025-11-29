<?php
// Chronos - Gamified Time Tracker
// Single-file implementation

// -----------------------------------------------------------------------------
// 1. Database & Setup
// -----------------------------------------------------------------------------

$db_file = __DIR__ . '/database.sqlite';
$dsn = 'sqlite:' . $db_file;

try {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

function migrate_db($pdo) {
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_stats (
        user_ip TEXT PRIMARY KEY,
        streak INTEGER DEFAULT 0,
        last_log_date TEXT
    )");
}

migrate_db($pdo);

// -----------------------------------------------------------------------------
// 2. API Routing & Logic
// -----------------------------------------------------------------------------

function json_response($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function get_input() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

if (isset($_GET['api'])) {
    $endpoint = $_GET['api'];
    $method = $_SERVER['REQUEST_METHOD'];
    $ip = $_SERVER['REMOTE_ADDR'];
    $input = get_input();

    try {
        if ($endpoint === 'get_data' && $method === 'GET') {
            // Requires client_date query param to filter today's logs correctly
            $client_date = $_GET['client_date'] ?? date('Y-m-d');

            // Fetch Skills
            $stmt = $pdo->prepare("SELECT * FROM skills WHERE user_ip = ? ORDER BY id DESC");
            $stmt->execute([$ip]);
            $skills = $stmt->fetchAll();

            // Fetch Today's Logs
            $stmt = $pdo->prepare("SELECT * FROM logs WHERE user_ip = ? AND date = ? ORDER BY id DESC");
            $stmt->execute([$ip, $client_date]);
            $logs = $stmt->fetchAll();

            // Fetch User Stats
            $stmt = $pdo->prepare("SELECT * FROM user_stats WHERE user_ip = ?");
            $stmt->execute([$ip]);
            $stats = $stmt->fetch();

            if (!$stats) {
                // Initialize stats if empty
                $stmt = $pdo->prepare("INSERT INTO user_stats (user_ip, streak) VALUES (?, 0)");
                $stmt->execute([$ip]);
                $stats = ['user_ip' => $ip, 'streak' => 0, 'last_log_date' => null];
            }

            json_response([
                'success' => true,
                'skills' => $skills,
                'logs' => $logs,
                'stats' => $stats,
                'server_time' => time()
            ]);

        } elseif ($endpoint === 'add_skill' && $method === 'POST') {
            if (empty($input['name'])) {
                json_response(['success' => false, 'error' => 'Name required']);
            }
            $goal = isset($input['goal']) ? (int)$input['goal'] : 60;

            $stmt = $pdo->prepare("INSERT INTO skills (user_ip, name, daily_goal) VALUES (?, ?, ?)");
            $stmt->execute([$ip, $input['name'], $goal]);

            json_response(['success' => true, 'id' => $pdo->lastInsertId()]);

        } elseif ($endpoint === 'update_skill' && $method === 'POST') {
             if (empty($input['id']) || empty($input['name'])) {
                json_response(['success' => false, 'error' => 'ID and Name required']);
            }
            $goal = isset($input['goal']) ? (int)$input['goal'] : 60;

            $stmt = $pdo->prepare("UPDATE skills SET name = ?, daily_goal = ? WHERE id = ? AND user_ip = ?");
            $stmt->execute([$input['name'], $goal, $input['id'], $ip]);

            json_response(['success' => true]);

        } elseif ($endpoint === 'delete_skill' && $method === 'POST') {
            if (empty($input['id'])) {
                json_response(['success' => false, 'error' => 'ID required']);
            }

            // Delete skill and its history
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("DELETE FROM logs WHERE skill_id = ? AND user_ip = ?");
                $stmt->execute([$input['id'], $ip]);

                $stmt = $pdo->prepare("DELETE FROM skills WHERE id = ? AND user_ip = ?");
                $stmt->execute([$input['id'], $ip]);

                $pdo->commit();
                json_response(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        } elseif ($endpoint === 'allocate' && $method === 'POST') {
            if (empty($input['id']) || empty($input['amount']) || empty($input['client_date'])) {
                json_response(['success' => false, 'error' => 'ID, Amount, and Client Date required']);
            }

            $skill_id = $input['id'];
            $amount = (int)$input['amount'];
            $client_date = $input['client_date'];

            $pdo->beginTransaction();

            try {
                // 1. Check Daily Budget (only for positive amounts)
                if ($amount > 0) {
                    $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM logs WHERE user_ip = ? AND date = ?");
                    $stmt->execute([$ip, $client_date]);
                    $result = $stmt->fetch();
                    $daily_used = $result['total'] ? (int)$result['total'] : 0;

                    if (($daily_used + $amount) > 840) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'error' => 'Daily budget exceeded']);
                    }
                }

                // 2. Gamification Logic
                $xp_gained = 0;
                $is_crit = 0;

                if ($amount > 0) {
                    // 5% Chance for Crit
                    if (rand(1, 100) <= 5) {
                        $is_crit = 1;
                        $xp_gained = $amount * 100;
                    } else {
                        $xp_gained = $amount * 20;
                    }
                } else {
                    // Correction: reduce XP by base rate
                    $xp_gained = $amount * 20;
                }

                // 3. Update Skill & Streaks
                $stmt = $pdo->prepare("SELECT * FROM skills WHERE id = ? AND user_ip = ?");
                $stmt->execute([$skill_id, $ip]);
                $skill = $stmt->fetch();

                if (!$skill) {
                    throw new Exception("Skill not found");
                }

                $new_skill_streak = $skill['current_streak'];
                $update_streak = false;

                // Only update streak logic on positive input
                if ($amount > 0) {
                     $last_log = $skill['last_log_date'];
                     $yesterday = date('Y-m-d', strtotime($client_date . ' -1 day'));

                     if ($last_log === $yesterday) {
                         $new_skill_streak++;
                     } elseif ($last_log !== $client_date) {
                         // Reset if missed a day (and it's not already today)
                         $new_skill_streak = 1;
                     }
                     // If last_log === client_date, keep streak
                     $update_streak = true;
                }

                $sql = "UPDATE skills SET total_minutes = total_minutes + ?, xp = xp + ?";
                $params = [$amount, $xp_gained];

                if ($update_streak) {
                    $sql .= ", current_streak = ?, last_log_date = ?";
                    $params[] = $new_skill_streak;
                    $params[] = $client_date;
                }

                $sql .= " WHERE id = ?";
                $params[] = $skill_id;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                // 4. Insert Log
                $stmt = $pdo->prepare("INSERT INTO logs (user_ip, skill_id, amount, xp_gained, is_crit, date) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$ip, $skill_id, $amount, $xp_gained, $is_crit, $client_date]);

                // 5. Update Global User Stats
                // Only update streak logic on positive input
                if ($amount > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM user_stats WHERE user_ip = ?");
                    $stmt->execute([$ip]);
                    $stats = $stmt->fetch();

                    if (!$stats) {
                        // Should have been created in get_data, but just in case
                         $stmt = $pdo->prepare("INSERT INTO user_stats (user_ip, streak) VALUES (?, 0)");
                         $stmt->execute([$ip]);
                         $stats = ['streak' => 0, 'last_log_date' => null];
                    }

                    $new_global_streak = $stats['streak'];
                    $last_global_log = $stats['last_log_date'];
                    $yesterday = date('Y-m-d', strtotime($client_date . ' -1 day'));

                    if ($last_global_log === $yesterday) {
                        $new_global_streak++;
                    } elseif ($last_global_log !== $client_date) {
                        $new_global_streak = 1;
                    }

                    $stmt = $pdo->prepare("UPDATE user_stats SET streak = ?, last_log_date = ? WHERE user_ip = ?");
                    $stmt->execute([$new_global_streak, $client_date, $ip]);
                }

                $pdo->commit();

                // Get updated daily total for response
                $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM logs WHERE user_ip = ? AND date = ?");
                $stmt->execute([$ip, $client_date]);
                $result = $stmt->fetch();
                $new_daily_total = $result['total'] ? (int)$result['total'] : 0;

                json_response([
                    'success' => true,
                    'crit' => (bool)$is_crit,
                    'xp' => $xp_gained,
                    'new_minutes' => $new_daily_total
                ]);

            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        } else {
            json_response(['success' => false, 'error' => 'Unknown endpoint']);
        }

    } catch (Exception $e) {
        http_response_code(500);
        json_response(['success' => false, 'error' => $e->getMessage()]);
    }
}

// -----------------------------------------------------------------------------
// 3. Frontend (HTML/JS)
// -----------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chronos</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: {
                            950: '#020617',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; }
        .mono { font-family: monospace; }

        /* Toast Animation */
        @keyframes slideIn {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .toast-enter { animation: slideIn 0.3s ease-out forwards; }
    </style>
</head>
<body class="bg-slate-950 text-slate-200 min-h-screen p-4 flex flex-col items-center">

    <!-- Application Root -->
    <div id="app" class="w-full max-w-5xl">
        <div class="text-center mt-20 text-slate-500 animate-pulse">Loading Chronos...</div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2"></div>

    <script>
        // -----------------------------------------------------------------------------
        // CLIENT-SIDE LOGIC
        // -----------------------------------------------------------------------------

        const API_BASE = 'index.php?api=';

        // Helper: Get Current Date (YYYY-MM-DD)
        const getClientDate = () => {
            const d = new Date();
            const year = d.getFullYear();
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        };

        // Helper: API Fetcher
        const api = {
            get: async (endpoint, params = {}) => {
                const url = new URL(API_BASE + endpoint, window.location.href);
                Object.keys(params).forEach(key => url.searchParams.append(key, params[key]));
                const res = await fetch(url);
                return res.json();
            },
            post: async (endpoint, body) => {
                const res = await fetch(API_BASE + endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                return res.json();
            }
        };

        // Helper: HTML Escaping for Security
        const escapeHtml = (unsafe) => {
            return unsafe
                 .replace(/&/g, "&amp;")
                 .replace(/</g, "&lt;")
                 .replace(/>/g, "&gt;")
                 .replace(/"/g, "&quot;")
                 .replace(/'/g, "&#039;");
        };

        // Game Logic: OSRS XP Table (Simplified for on-fly calc)
        // Level L requires approx Diff = floor((L + 300 * 2^(L/7))/4)
        // Since we need to display level and progress, we can pre-calculate or approximate.
        // For accurate display, we should implement the inverse or a lookup table.
        // Given the constraints, I'll implement a simple lookup generator up to lvl 99.

        const XP_TABLE = [];
        let total_xp = 0;
        for (let l = 1; l < 100; l++) {
            XP_TABLE[l] = total_xp;
            const diff = Math.floor((l + 300 * Math.pow(2, l / 7.0)) / 4.0);
            total_xp += diff;
        }

        const getLevelInfo = (xp) => {
            let level = 99;
            for (let l = 1; l < 100; l++) {
                if (xp < XP_TABLE[l+1]) {
                    level = l;
                    break;
                }
            }
            if (level >= 99) return { level: 99, current: xp, next: XP_TABLE[99], progress: 100 };

            const startXp = XP_TABLE[level];
            const nextXp = XP_TABLE[level + 1];
            const progress = ((xp - startXp) / (nextXp - startXp)) * 100;

            return { level, current: xp, next: nextXp, progress };
        };

        // State Management
        const store = {
            data: {
                skills: [],
                logs: [],
                stats: { streak: 0, last_log_date: null }
            },
            ui: {
                loading: true,
                settingsOpen: false,
                editingSkill: null // id or null
            },

            async init() {
                this.ui.loading = true;
                render();
                const res = await api.get('get_data', { client_date: getClientDate() });
                if (res.success) {
                    this.data.skills = res.skills;
                    this.data.logs = res.logs;
                    this.data.stats = res.stats;
                }
                this.ui.loading = false;
                render();
                setInterval(render, 1000); // Re-render every second for countdown
            },

            async addSkill(name, goal) {
                const res = await api.post('add_skill', { name, goal });
                if (res.success) {
                    await this.init(); // Refresh all
                    showToast('Skill created', 'success');
                } else {
                    showToast(res.error, 'error');
                }
            },

            async updateSkill(id, name, goal) {
                const res = await api.post('update_skill', { id, name, goal });
                if (res.success) {
                    await this.init();
                    showToast('Skill updated', 'success');
                } else {
                    showToast(res.error, 'error');
                }
            },

            async deleteSkill(id) {
                 if(!confirm("Irreversibly delete this skill and all its history?")) return;
                 const res = await api.post('delete_skill', { id });
                 if (res.success) {
                     await this.init();
                     showToast('Skill deleted', 'info');
                 } else {
                     showToast(res.error, 'error');
                 }
            },

            async allocate(id, amount) {
                const res = await api.post('allocate', {
                    id,
                    amount,
                    client_date: getClientDate()
                });

                if (res.success) {
                    await this.init();
                    if (res.crit) {
                        showToast(`CRITICAL FOCUS! +${res.xp} XP`, 'crit');
                    } else if (amount > 0) {
                        showToast(`+${res.xp} XP`, 'success');
                    } else {
                        showToast(`Correction: ${amount} min`, 'info');
                    }
                } else {
                    showToast(res.error, 'error');
                }
            }
        };

        // Render Logic
        const render = () => {
            const app = document.getElementById('app');

            if (store.ui.loading) {
                app.innerHTML = `<div class="text-center mt-20 text-slate-500 animate-pulse">Loading Chronos...</div>`;
                return;
            }

            // Calculations
            const todayLogs = store.data.logs;
            const minutesUsed = todayLogs.reduce((acc, log) => acc + parseInt(log.amount), 0);
            const budget = 840;
            const remainingBudget = budget - minutesUsed;

            // Countdown Logic
            const now = new Date();
            const midnight = new Date(now);
            midnight.setHours(24, 0, 0, 0);
            const msToMidnight = midnight - now;
            const minutesToMidnight = Math.floor(msToMidnight / 60000);

            const displayMinutes = Math.min(remainingBudget, minutesToMidnight);
            const isUrgent = displayMinutes < 60;

            // Status Tier
            let statusTier = { name: 'WASTING TIME', color: 'text-red-500', barColor: 'bg-red-500' };
            if (minutesUsed >= 720) statusTier = { name: 'LEGENDARY', color: 'text-yellow-400', barColor: 'bg-yellow-400' };
            else if (minutesUsed >= 480) statusTier = { name: 'OPTIMAL', color: 'text-emerald-400', barColor: 'bg-emerald-400' };
            else if (minutesUsed >= 240) statusTier = { name: 'BUILDING', color: 'text-orange-400', barColor: 'bg-orange-400' };

            // HTML Construction
            app.innerHTML = `
                <!-- HUD -->
                <div class="mb-8">
                    <div class="flex justify-between items-end mb-2">
                        <div>
                            <div class="text-xs text-slate-500 font-bold tracking-widest">STATUS TIER</div>
                            <div class="text-2xl font-black ${statusTier.color} drop-shadow-sm">${statusTier.name}</div>
                        </div>
                        <div class="text-right">
                             <div class="text-xs text-slate-500 font-bold tracking-widest uppercase">Global Streak</div>
                             <div class="flex items-center justify-end gap-1 text-xl font-mono text-orange-500">
                                <svg class="w-5 h-5 ${store.data.stats.streak > 0 ? 'animate-pulse' : ''}" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 01-.945-1.067c-.328-.68-.398-1.534-.398-2.654A1 1 0 005.05 6.05 6.981 6.981 0 003 11a7 7 0 1011.95-4.95c-.592-.591-.98-.985-1.348-1.467-.363-.476-.724-1.063-1.207-2.03zM12.12 15.12A3 3 0 017 13s.879.5 2.5.5c0-1 .5-4 1.25-4.5.5 1 .786 1.293 1.371 1.879A2.99 2.99 0 0113 13a2.99 2.99 0 01-.879 2.121z" clip-rule="evenodd"></path></svg>
                                ${store.data.stats.streak}
                             </div>
                        </div>
                    </div>

                    <!-- Utilization Bar -->
                    <div class="h-4 bg-slate-900 rounded-full overflow-hidden mb-4 border border-slate-800">
                        <div class="h-full ${statusTier.barColor} transition-all duration-500 ease-out" style="width: ${(minutesUsed / budget) * 100}%"></div>
                    </div>

                    <!-- Countdown -->
                    <div class="text-center p-6 border border-slate-800 bg-slate-900/50 rounded-lg">
                        <div class="text-sm text-slate-400 uppercase tracking-widest mb-1">Time Budget Remaining</div>
                        <div class="text-6xl font-black mono ${isUrgent ? 'text-red-500 animate-pulse' : 'text-slate-100'}">
                            ${Math.floor(displayMinutes / 60)}h ${displayMinutes % 60}m
                        </div>
                        <div class="text-xs text-slate-600 mt-2">Daily Cap: ${budget}m • Used: ${minutesUsed}m</div>
                    </div>
                </div>

                <!-- Create Skill Button (if empty) -->
                ${store.data.skills.length === 0 ? `
                    <button class="w-full border-2 border-dashed border-slate-800 rounded-lg p-12 text-center hover:border-slate-700 transition cursor-pointer group" onclick="openSettings(null)">
                        <div class="text-slate-600 group-hover:text-slate-400">Initialize Protocol...</div>
                    </button>
                ` : ''}

                <!-- Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-20">
                    ${store.data.skills.map(skill => renderSkillCard(skill, remainingBudget, todayLogs)).join('')}

                    <!-- Add New Card -->
                    <button onclick="openSettings(null)" class="w-full border-2 border-dashed border-slate-800 rounded-lg flex items-center justify-center min-h-[200px] hover:border-slate-700 hover:bg-slate-900/30 cursor-pointer transition text-slate-600 hover:text-slate-400">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    </button>
                </div>

                <!-- Modals -->
                ${store.ui.settingsOpen ? renderSettingsModal() : ''}
            `;
        };

        const renderSkillCard = (skill, remainingBudget, logs) => {
            const lvl = getLevelInfo(skill.xp);

            // Calculate daily progress for this skill
            const skillLogsToday = logs.filter(l => l.skill_id === skill.id);
            const minutesToday = skillLogsToday.reduce((acc, l) => acc + parseInt(l.amount), 0);
            const isComplete = minutesToday >= skill.daily_goal;
            const isUrgent = minutesToday === 0; // "Red border/bg if 0 minutes logged today"

            let borderClass = 'border-slate-800 bg-slate-900/50';
            if (isComplete) borderClass = 'border-emerald-900/50 bg-emerald-950/10';
            else if (isUrgent) borderClass = 'border-red-900/30 bg-red-950/10';

            return `
                <div class="relative group border ${borderClass} rounded-lg p-5 transition-all hover:border-slate-700">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <h3 class="font-bold text-lg text-slate-100">${escapeHtml(skill.name)}</h3>
                            <div class="text-xs text-yellow-500 font-mono">Lvl ${lvl.level}</div>
                        </div>
                        <button onclick="openSettings(${skill.id})" class="text-slate-600 hover:text-slate-300">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </button>
                    </div>

                    <!-- XP Bar -->
                    <div class="w-full bg-slate-900 h-1.5 rounded-full mb-4 overflow-hidden">
                        <div class="bg-yellow-500 h-full" style="width: ${lvl.progress}%"></div>
                    </div>

                    <!-- Stats Grid -->
                    <div class="grid grid-cols-3 gap-2 text-center text-xs mb-4 text-slate-400">
                        <div class="bg-slate-900/50 p-1 rounded">
                            <div class="font-mono text-slate-200">${minutesToday}/${skill.daily_goal}</div>
                            <div>Daily</div>
                        </div>
                        <div class="bg-slate-900/50 p-1 rounded">
                            <div class="font-mono text-slate-200">${skill.total_minutes}</div>
                            <div>Total</div>
                        </div>
                        <div class="bg-slate-900/50 p-1 rounded">
                             <div class="font-mono text-orange-400 flex justify-center items-center gap-1">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 01-.945-1.067c-.328-.68-.398-1.534-.398-2.654A1 1 0 005.05 6.05 6.981 6.981 0 003 11a7 7 0 1011.95-4.95c-.592-.591-.98-.985-1.348-1.467-.363-.476-.724-1.063-1.207-2.03zM12.12 15.12A3 3 0 017 13s.879.5 2.5.5c0-1 .5-4 1.25-4.5.5 1 .786 1.293 1.371 1.879A2.99 2.99 0 0113 13a2.99 2.99 0 01-.879 2.121z" clip-rule="evenodd"></path></svg>
                                ${skill.current_streak}
                             </div>
                            <div>Streak</div>
                        </div>
                    </div>

                    <!-- Controls -->
                    <div class="grid grid-cols-3 gap-2">
                        <button onclick="store.allocate(${skill.id}, 15)" ${remainingBudget < 15 ? 'disabled' : ''} class="bg-slate-800 hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed text-slate-200 py-1 rounded text-sm font-mono transition">+15</button>
                        <button onclick="store.allocate(${skill.id}, 30)" ${remainingBudget < 30 ? 'disabled' : ''} class="bg-slate-800 hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed text-slate-200 py-1 rounded text-sm font-mono transition">+30</button>
                        <button onclick="store.allocate(${skill.id}, 60)" ${remainingBudget < 60 ? 'disabled' : ''} class="bg-slate-800 hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed text-slate-200 py-1 rounded text-sm font-mono transition">+60</button>
                    </div>
                </div>
            `;
        };

        const renderSettingsModal = () => {
            const skill = store.ui.editingSkill
                ? store.data.skills.find(s => s.id === store.ui.editingSkill)
                : { name: '', daily_goal: 60 };

            const isNew = !store.ui.editingSkill;

            return `
                <div class="fixed inset-0 bg-black/80 flex items-center justify-center z-40" onclick="closeSettings(event)">
                    <div class="bg-slate-900 border border-slate-800 rounded-lg p-6 w-full max-w-md shadow-2xl" onclick="event.stopPropagation()">
                        <h2 class="text-xl font-bold text-slate-100 mb-4">${isNew ? 'New Protocol' : 'Protocol Settings'}</h2>

                        <div class="space-y-4 mb-6" onkeydown="if(event.key === 'Enter') saveSettings(${isNew ? 'null' : skill.id})">
                            <div>
                                <label class="block text-xs text-slate-500 uppercase font-bold mb-1">Name</label>
                                <input type="text" id="setting-name" value="${escapeHtml(skill.name)}" class="w-full bg-slate-950 border border-slate-800 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500" placeholder="e.g. Deep Work" autofocus>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-500 uppercase font-bold mb-1">Daily Goal (Minutes)</label>
                                <input type="number" id="setting-goal" value="${skill.daily_goal}" class="w-full bg-slate-950 border border-slate-800 rounded p-2 text-slate-200 focus:outline-none focus:border-indigo-500">
                            </div>
                        </div>

                        ${!isNew ? `
                        <div class="mb-6 border-t border-slate-800 pt-4">
                             <label class="block text-xs text-slate-500 uppercase font-bold mb-2">Corrections (Negative)</label>
                             <div class="grid grid-cols-3 gap-2">
                                <button onclick="handleCorrection(${skill.id}, -15)" class="bg-slate-950 border border-slate-800 hover:bg-red-950/30 hover:border-red-900/50 text-red-400 py-1 rounded text-sm font-mono transition">-15</button>
                                <button onclick="handleCorrection(${skill.id}, -30)" class="bg-slate-950 border border-slate-800 hover:bg-red-950/30 hover:border-red-900/50 text-red-400 py-1 rounded text-sm font-mono transition">-30</button>
                                <button onclick="handleCorrection(${skill.id}, -60)" class="bg-slate-950 border border-slate-800 hover:bg-red-950/30 hover:border-red-900/50 text-red-400 py-1 rounded text-sm font-mono transition">-60</button>
                             </div>
                        </div>
                        ` : ''}

                        <div class="flex justify-between items-center">
                            ${!isNew ? `<button onclick="store.deleteSkill(${skill.id}); closeSettings(null)" class="text-red-500 text-sm hover:underline">Delete Protocol</button>` : '<div></div>'}

                            <div class="flex gap-2">
                                <button onclick="closeSettings(null)" class="px-4 py-2 text-slate-400 hover:text-slate-200 transition">Cancel</button>
                                <button onclick="saveSettings(${isNew ? 'null' : skill.id})" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded font-bold transition">Save</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        };

        // Actions
        window.openSettings = (id) => {
            store.ui.editingSkill = id;
            store.ui.settingsOpen = true;
            render();
        };

        window.closeSettings = (e) => {
             // If event is passed, check if it's the backdrop
             if (e && e.target !== e.currentTarget) return;
             store.ui.settingsOpen = false;
             store.ui.editingSkill = null;
             render();
        };

        window.saveSettings = (id) => {
            const name = document.getElementById('setting-name').value;
            const goal = document.getElementById('setting-goal').value;
            if (!name) return showToast('Name is required', 'error');

            if (id) {
                store.updateSkill(id, name, goal);
            } else {
                store.addSkill(name, goal);
            }
            store.ui.settingsOpen = false;
            store.ui.editingSkill = null;
        };

        window.handleCorrection = async (id, amount) => {
            await store.allocate(id, amount);
            store.ui.settingsOpen = false;
            store.ui.editingSkill = null;
        };

        // Toast System
        window.showToast = (msg, type = 'info') => {
            const container = document.getElementById('toast-container');
            const el = document.createElement('div');

            let colors = 'bg-slate-800 text-slate-200 border-slate-700';
            if (type === 'success') colors = 'bg-emerald-950 text-emerald-200 border-emerald-800';
            if (type === 'error') colors = 'bg-red-950 text-red-200 border-red-800';
            if (type === 'crit') colors = 'bg-yellow-950 text-yellow-200 border-yellow-600 shadow-[0_0_15px_rgba(234,179,8,0.3)]';

            el.className = `p-4 rounded shadow-lg border text-sm font-bold flex items-center gap-2 toast-enter ${colors}`;

            let icon = '';
            if (type === 'crit') icon = '<svg class="w-5 h-5 animate-pulse" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"></path></svg>';

            el.innerHTML = `${icon}<span>${msg}</span>`;

            container.appendChild(el);
            setTimeout(() => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(100%)';
                el.style.transition = 'all 0.3s';
                setTimeout(() => el.remove(), 300);
            }, 4000);
        };

        // Init
        store.init();

    </script>
</body>
</html>
