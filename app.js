// app.js

const API_BASE = 'index.php?api=';

// State
let state = {
    skills: [],
    logs: [],
    stats: {},
    minutes_today: 0,
    daily_cap: 840
};

// --- API Client ---

async function api(endpoint, method = 'GET', data = null) {
    const options = {
        method,
        headers: {
            'Content-Type': 'application/json'
        }
    };
    if (data) {
        options.body = JSON.stringify(data);
    }

    try {
        const response = await fetch(API_BASE + endpoint, options);
        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.error || 'API Error');
        }
        return result;
    } catch (error) {
        showToast(error.message, 'error');
        throw error;
    }
}

// --- Logic ---

function formatTime(minutes) {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return `${h}h ${m}m`;
}

function calculateTier(minutes) {
    if (minutes < 240) return { name: 'WASTING TIME', color: 'text-red-500', bg: 'bg-red-500/10', animate: true };
    if (minutes < 480) return { name: 'BUILDING', color: 'text-orange-500', bg: 'bg-orange-500/10', animate: false };
    if (minutes < 720) return { name: 'OPTIMAL', color: 'text-emerald-500', bg: 'bg-emerald-500/10', animate: false };
    return { name: 'LEGENDARY', color: 'text-yellow-400', bg: 'bg-yellow-400/10', animate: false }; // Gold
}

function updateTimer() {
    const now = new Date();
    const midnight = new Date();
    midnight.setHours(24, 0, 0, 0);

    const minutesToMidnight = Math.floor((midnight - now) / 1000 / 60);
    const budgetRemaining = state.daily_cap - state.minutes_today;

    // Display the lesser of the two
    const displayMinutes = Math.max(0, Math.min(minutesToMidnight, budgetRemaining));

    const h = Math.floor(displayMinutes / 60);
    const m = displayMinutes % 60;

    const timeString = `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}`;

    const timerDisplay = document.getElementById('timer-display');
    if (timerDisplay) timerDisplay.textContent = timeString;

    // Update Tier Badge
    const tier = calculateTier(state.minutes_today);
    const tierBadge = document.getElementById('tier-badge');
    if (tierBadge) {
        tierBadge.textContent = tier.name;
        tierBadge.className = `mt-2 px-3 py-1 rounded-full text-xs font-bold ${tier.color} ${tier.bg} ${tier.animate ? 'animate-pulse' : ''}`;
    }
}

// --- UI Rendering ---

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function render() {
    // Render Stats
    document.getElementById('global-streak').textContent = state.stats.streak || 0;
    document.getElementById('total-minutes-today').textContent = state.minutes_today || 0;
    document.getElementById('active-skills-count').textContent = state.skills.length;

    let xpToday = 0;
    if (state.logs) {
        xpToday = state.logs.reduce((acc, log) => acc + log.xp_gained, 0);
    }
    document.getElementById('xp-today').textContent = xpToday;

    // Render Skills
    const skillsList = document.getElementById('skills-list');
    skillsList.innerHTML = '';

    state.skills.forEach(skill => {
        const percent = (skill.current_level_xp && skill.next_level_xp)
            ? ((skill.xp - skill.current_level_xp) / (skill.next_level_xp - skill.current_level_xp)) * 100
            : 0;

        // Log count for today for this skill
        const todaysLog = state.logs ? state.logs.filter(l => l.skill_id === skill.id).reduce((acc, l) => acc + l.amount, 0) : 0;
        const goalPercent = Math.min(100, (todaysLog / skill.daily_goal) * 100);

        const card = document.createElement('div');
        card.className = 'bg-slate-800 rounded-lg p-5 border border-slate-700 hover:border-slate-600 transition-colors relative overflow-hidden group';

        // Use escapeHtml for user input
        card.innerHTML = `
            <div class="flex justify-between items-start mb-2">
                <div>
                    <h3 class="font-bold text-lg text-white">${escapeHtml(skill.name)}</h3>
                    <div class="text-xs text-slate-400">Level ${skill.level} <span class="text-slate-600 mx-1">|</span> ${skill.xp} XP</div>
                </div>
                <div class="text-right">
                    <div class="text-2xl font-bold text-indigo-400">${todaysLog}m</div>
                    <div class="text-xs text-slate-500">of ${skill.daily_goal}m goal</div>
                </div>
            </div>

            <!-- Level Progress -->
            <div class="w-full bg-slate-900 h-1.5 rounded-full mt-2 mb-4 overflow-hidden">
                <div class="bg-indigo-500 h-full progress-bar" style="width: ${Math.max(0, Math.min(100, percent))}%"></div>
            </div>

            <div class="flex justify-between items-center mt-4">
                <div class="flex items-center gap-2">
                   <div class="text-xs font-mono bg-slate-900 px-2 py-1 rounded text-orange-400 border border-orange-400/20" title="Current Streak">
                        🔥 ${skill.current_streak}
                   </div>
                </div>

                <div class="flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button onclick="openEditSkill(${skill.id})" class="text-slate-500 hover:text-slate-300 p-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
                    </button>
                    <button onclick="deleteSkill(${skill.id})" class="text-slate-500 hover:text-red-400 p-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </button>
                </div>

                 <button onclick="openAllocate(${skill.id})" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded shadow-lg shadow-indigo-500/20 transform active:scale-95 transition-all">
                    LOG TIME
                </button>
            </div>

            <!-- Daily Goal Progress Overlay (subtle) -->
             <div class="absolute bottom-0 left-0 h-1 bg-emerald-500/50 transition-all" style="width: ${goalPercent}%"></div>
        `;

        skillsList.appendChild(card);
    });
}

// --- Actions ---

async function loadData() {
    try {
        const data = await api('get_data');
        state = data;
        render();
        updateTimer();
    } catch (e) {
        console.error(e);
    }
}

async function handleSkillSubmit(e) {
    e.preventDefault();
    const id = document.getElementById('skill-id').value;
    const name = document.getElementById('skill-name').value;
    const goal = document.getElementById('skill-goal').value;

    if (!name) return;

    try {
        if (id) {
            await api('update_skill', 'POST', { id, name, goal });
            showToast('Protocol updated.');
        } else {
            await api('add_skill', 'POST', { name, goal });
            showToast('New protocol initialized.', 'success');
        }
        closeModal('add-skill-modal');
        loadData();
    } catch (e) {}
}

async function deleteSkill(id) {
    if (!confirm('Are you sure? This action is irreversible.')) return;

    try {
        await api('delete_skill', 'POST', { id });
        showToast('Protocol terminated.', 'info');
        loadData();
    } catch (e) {}
}

async function handleAllocate(e) {
    e.preventDefault();
    const id = document.getElementById('allocate-skill-id').value;
    const amount = document.getElementById('allocate-amount').value;

    try {
        const result = await api('allocate', 'POST', { id, amount });

        if (result.success) {
            closeModal('allocate-modal');

            if (result.crit) {
                showToast(`CRITICAL FOCUS! +${result.xp} XP (5x Multiplier)`, 'gold');
            } else {
                showToast(`Log committed. +${result.xp} XP`, 'success');
            }

            loadData();
        }
    } catch (e) {}
}

// --- Helpers ---

function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
    if (id === 'add-skill-modal') {
        // Reset form if opening clean
         if (!document.getElementById('skill-id').value) {
             document.getElementById('skill-form').reset();
             document.getElementById('skill-goal').value = 60;
             document.getElementById('modal-title').textContent = "Initialize Protocol";
         }
    }
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
    // Clear hidden id fields
    if (id === 'add-skill-modal') {
        document.getElementById('skill-id').value = '';
    }
}

function openEditSkill(id) {
    const skill = state.skills.find(s => s.id == id);
    if (!skill) return;

    document.getElementById('skill-id').value = skill.id;
    document.getElementById('skill-name').value = skill.name;
    document.getElementById('skill-goal').value = skill.daily_goal;
    document.getElementById('modal-title').textContent = "Update Protocol";

    openModal('add-skill-modal');
}

function openAllocate(id) {
    const skill = state.skills.find(s => s.id == id);
    if (!skill) return;

    document.getElementById('allocate-skill-id').value = id;
    document.getElementById('allocate-skill-name').textContent = skill.name;
    document.getElementById('allocate-amount').value = 30; // Default

    openModal('allocate-modal');
}

function adjustAmount(delta) {
    const input = document.getElementById('allocate-amount');
    let val = parseInt(input.value) || 0;
    val += delta;
    input.value = val;
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');

    let colors = 'bg-slate-800 border-slate-600 text-white';
    if (type === 'success') colors = 'bg-emerald-900/90 border-emerald-500 text-emerald-100';
    if (type === 'error') colors = 'bg-red-900/90 border-red-500 text-red-100';
    if (type === 'gold') colors = 'bg-yellow-900/90 border-yellow-400 text-yellow-100 shadow-[0_0_15px_rgba(250,204,21,0.5)]';

    toast.className = `toast-enter p-4 rounded shadow-lg border ${colors} min-w-[300px] flex items-center justify-between`;
    // Use escapeHtml for message content to be safe
    toast.innerHTML = `<span class="font-medium">${escapeHtml(message)}</span>`;

    container.appendChild(toast);

    setTimeout(() => {
        toast.classList.remove('toast-enter');
        toast.classList.add('toast-exit');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// --- Initialization ---

window.addEventListener('DOMContentLoaded', () => {
    loadData();
    setInterval(updateTimer, 1000);
});
