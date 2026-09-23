<?php
// Ambil daftar pengguna untuk dropdown PIC.
$users_result = $conn->query("SELECT email, username FROM users ORDER BY username ASC");
$users_list = [];
if ($users_result && $users_result->num_rows > 0) {
    while($user_row = $users_result->fetch_assoc()) {
        $users_list[] = $user_row;
    }
}
?>
<!-- ponytail: Better-UI & Emil-Design-Eng Compact Zero-Scroll Form Layout -->
<style>
    .form-card {
        background: rgba(15, 23, 42, 0.65);
        border: 1px solid rgba(51, 65, 85, 0.65);
        border-radius: 12px;
        padding: 10px 12px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    html.light .form-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
    }
    .form-card-title {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #94a3b8;
        padding-bottom: 4px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }
    html.light .form-card-title {
        color: #475569;
        border-bottom-color: #e2e8f0;
    }
    .form-micro-label {
        display: block;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #94a3b8;
        margin-bottom: 2px;
    }
    html.light .form-micro-label {
        color: #64748b;
    }
    .compact-input {
        width: 100%;
        padding: 5px 8px;
        font-size: 12px;
        border-radius: 8px;
        background-color: var(--input-bg, rgba(30, 41, 59, 0.7));
        border: 1px solid var(--input-border, #475569);
        color: var(--text-primary, #e2e8f0);
        outline: none;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    html.light .compact-input {
        background-color: #ffffff;
        border-color: #cbd5e1;
        color: #0f172a;
    }
    .compact-input:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25);
    }
    .checklist-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 8px;
        border-radius: 6px;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        font-size: 11px;
        font-weight: 500;
        color: #cbd5e1;
        cursor: pointer;
        transition: background 0.15s ease, border-color 0.15s ease;
    }
    html.light .checklist-chip {
        background: #ffffff;
        border-color: #e2e8f0;
        color: #334155;
    }
    .checklist-chip:hover {
        background: rgba(59, 130, 246, 0.1);
        border-color: rgba(59, 130, 246, 0.3);
    }

    /* Compact Quill inside modal */
    #task-modal .ql-toolbar {
        padding: 2px 6px;
        border-top-left-radius: 8px;
        border-top-right-radius: 8px;
        border-color: var(--glass-border, #475569) !important;
        background: rgba(0, 0, 0, 0.15);
    }
    html.light #task-modal .ql-toolbar {
        background: #f8fafc;
        border-color: #cbd5e1 !important;
    }
    #task-modal .ql-container {
        border-bottom-left-radius: 8px;
        border-bottom-right-radius: 8px;
        border-color: var(--glass-border, #475569) !important;
    }
    html.light #task-modal .ql-container {
        border-color: #cbd5e1 !important;
    }
    #task-modal .ql-editor {
        min-height: 46px;
        max-height: 56px;
        padding: 4px 8px;
        font-size: 12px;
        color: var(--text-primary);
    }

    /* Modal Backdrop & Entrance/Exit Animations (Emil-Design-Eng) */
    @keyframes modalBackdropFadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    @keyframes modalBackdropFadeOut {
        from { opacity: 1; }
        to { opacity: 0; }
    }
    @keyframes modalScalePopIn {
        0% {
            opacity: 0;
            transform: scale(0.96) translateY(6px);
        }
        100% {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }
    @keyframes modalScalePopOut {
        0% {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
        100% {
            opacity: 0;
            transform: scale(0.96) translateY(4px);
        }
    }

    #task-modal:not(.hidden):not(.modal-closing),
    #redistribute-modal:not(.hidden):not(.modal-closing),
    #confirm-modal:not(.hidden):not(.modal-closing) {
        animation: modalBackdropFadeIn 0.18s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    #task-modal:not(.hidden):not(.modal-closing) .modal-content-wrapper,
    #task-modal:not(.hidden):not(.modal-closing) .glassmorphism-modal,
    #redistribute-modal:not(.hidden):not(.modal-closing) .glassmorphism-modal,
    #confirm-modal:not(.hidden):not(.modal-closing) #confirm-modal-box {
        animation: modalScalePopIn 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    #task-modal.modal-closing,
    #redistribute-modal.modal-closing,
    #confirm-modal.modal-closing {
        animation: modalBackdropFadeOut 0.15s cubic-bezier(0.4, 0, 1, 1) forwards;
        pointer-events: none;
    }
    #task-modal.modal-closing .modal-content-wrapper,
    #task-modal.modal-closing .glassmorphism-modal,
    #redistribute-modal.modal-closing .glassmorphism-modal,
    #confirm-modal.modal-closing #confirm-modal-box {
        animation: modalScalePopOut 0.15s cubic-bezier(0.4, 0, 1, 1) forwards;
    }

    /* Modal Button Hierarchy (Better-UI & Anti-Slop) */
    .modal-btn-cancel {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 14px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 500;
        color: #94a3b8;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        cursor: pointer;
        transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
        text-decoration: none;
    }
    .modal-btn-cancel:hover {
        background: rgba(255, 255, 255, 0.1);
        color: #f8fafc;
        border-color: rgba(255, 255, 255, 0.2);
    }
    .modal-btn-cancel:active {
        transform: scale(0.96);
    }
    html.light .modal-btn-cancel {
        background: #ffffff;
        color: #475569;
        border-color: #cbd5e1;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
    }
    html.light .modal-btn-cancel:hover {
        background: #f8fafc;
        color: #0f172a;
        border-color: #94a3b8;
    }

    .modal-btn-save {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 16px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 600;
        color: #ffffff;
        background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%);
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: 0 2px 8px rgba(37, 99, 235, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.25);
        cursor: pointer;
        transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .modal-btn-save:hover {
        background: linear-gradient(180deg, #60a5fa 0%, #3b82f6 100%);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.35);
        transform: translateY(-0.5px);
    }
    .modal-btn-save:active {
        transform: scale(0.96) translateY(0);
        box-shadow: 0 1px 4px rgba(37, 99, 235, 0.25);
    }
    html.light .modal-btn-save {
        background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
        border: 1px solid rgba(0, 0, 0, 0.1);
        box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.2);
    }
    html.light .modal-btn-save:hover {
        background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%);
    }
</style>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-3 text-left">
    <!-- ================= LEFT COLUMN: Model, Builds & Notes (6/12) ================= -->
    <div class="lg:col-span-6 flex flex-col gap-3">
        <!-- 1. Identitas Model & PIC -->
        <div class="form-card">
            <div class="form-card-title">
                <svg class="w-3.5 h-3.5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                <span>Model & Penugasan</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <div>
                    <label for="model_name" class="form-micro-label">Model Name <span class="text-rose-400">*</span></label>
                    <input type="text" id="model_name" name="model_name" class="compact-input font-medium" placeholder="SM-S921B..." required>
                </div>
                <div>
                    <label for="project_name" class="form-micro-label">Marketing Name <span class="text-rose-400">*</span></label>
                    <input type="text" id="project_name" name="project_name" class="compact-input font-medium" placeholder="Galaxy S24..." required>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 items-center pt-1">
                <div>
                    <label for="pic_email" class="form-micro-label">PIC Engineer <span class="text-rose-400">*</span></label>
                    <select id="pic_email" name="pic_email" class="compact-input font-medium" required>
                        <option value="" disabled selected>Pilih PIC...</option>
                        <?php foreach ($users_list as $user): ?>
                            <option value="<?= htmlspecialchars($user['email']) ?>">
                                <?= htmlspecialchars($user['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pt-4">
                    <label for="is_urgent_toggle" class="flex items-center gap-2 cursor-pointer px-3 py-1.5 rounded-xl bg-red-500/10 hover:bg-red-500/15 border border-red-500/20 hover:border-red-500/40 transition-all select-none">
                        <input type="hidden" name="is_urgent" value="0">
                        <input type="checkbox" value="1" id="is_urgent_toggle" name="is_urgent" class="w-4 h-4 text-red-600 rounded border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 focus:ring-red-500 focus:ring-offset-0 cursor-pointer">
                        <span class="text-xs font-semibold text-red-700 dark:text-red-400 flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></span>
                            Urgent Task
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <!-- 2. Build & Binaries -->
        <div class="form-card">
            <div class="form-card-title">
                <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                <span>Build Versions & QB ID</span>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label for="ap" class="form-micro-label">AP Version</label>
                    <input type="text" id="ap" name="ap" class="compact-input font-mono text-[11px]" placeholder="AP...">
                </div>
                <div>
                    <label for="cp" class="form-micro-label">CP Version</label>
                    <input type="text" id="cp" name="cp" class="compact-input font-mono text-[11px]" placeholder="CP...">
                </div>
                <div>
                    <label for="csc" class="form-micro-label">CSC Version</label>
                    <input type="text" id="csc" name="csc" class="compact-input font-mono text-[11px]" placeholder="CSC...">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2 pt-1">
                <div>
                    <label for="qb_user" class="form-micro-label">QB User ID</label>
                    <input type="text" id="qb_user" name="qb_user" class="compact-input font-mono text-[11px]" placeholder="e.g. 1012345">
                </div>
                <div>
                    <label for="qb_userdebug" class="form-micro-label">QB Userdebug ID</label>
                    <input type="text" id="qb_userdebug" name="qb_userdebug" class="compact-input font-mono text-[11px]" placeholder="e.g. 1012346">
                </div>
            </div>
        </div>

        <!-- 3. Catatan / Notes -->
        <div class="form-card flex-grow">
            <div class="form-card-title">
                <svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                <span>Catatan / Notes</span>
            </div>
            <input type="hidden" name="notes" id="notes-hidden-input">
            <div id="notes-editor" class="rounded-lg"></div>
        </div>
    </div>

    <!-- ================= RIGHT COLUMN: Test Plan, Dates & Submission (6/12) ================= -->
    <div class="lg:col-span-6 flex flex-col gap-3">
        <!-- 4. Test Plan & Progress Status -->
        <div class="form-card">
            <div class="form-card-title">
                <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>Test Plan & Status Checklist</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <div>
                    <label for="test_plan_type" class="form-micro-label">Type Test Plan <span class="text-rose-400">*</span></label>
                    <select id="test_plan_type" name="test_plan_type" class="compact-input font-medium" required>
                        <option>Regular Variant</option>
                        <option>SKU</option>
                        <option>Normal MR</option>
                        <option>SMR</option>
                        <option>Simple Exception MR</option>
                    </select>
                </div>
                <div>
                    <label for="progress_status" class="form-micro-label">Status Progress <span class="text-rose-400">*</span></label>
                    <select id="progress_status" name="progress_status" class="compact-input font-medium text-blue-400 font-semibold" required>
                        <option>Task Baru</option>
                        <option>Downloaded</option>
                        <option>Test Ongoing</option>
                        <option>Passed</option>
                        <option>Submitted</option>
                        <option>Approved</option>
                        <option>Pending Feedback</option>
                        <option>Feedback Sent</option>
                        <option>Batal</option>
                    </select>
                </div>
            </div>

            <!-- Interactive Checklist Items -->
            <div class="pt-1">
                <div id="checklist-placeholder" class="text-[11px] text-slate-400 italic">Pilih Test Plan untuk checklist item.</div>
                <?php
                $test_plan_items_form = [
                    'Regular Variant' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
                    'SKU' => ['CTS SKU', 'GTS-variant', 'ATM', 'CTS-Verifier'],
                    'Normal MR' => ['CTS', 'GTS', 'CTS-Verifier', 'ATM'],
                    'SMR' => ['CTS', 'GTS', 'STS', 'SCAT'],
                    'Simple Exception MR' => ['STS']
                ];
                foreach($test_plan_items_form as $plan => $items):
                    $plan_id = str_replace(' ', '_', $plan);
                ?>
                <div id="checklist-container-<?= $plan_id ?>" class="hidden">
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach($items as $item): 
                            $item_id = str_replace([' ', '-'], '_', $item);
                        ?>
                        <label class="checklist-chip" for="checklist_<?= $plan_id ?>_<?= $item_id ?>">
                            <input id="checklist_<?= $plan_id ?>_<?= $item_id ?>" name="checklist[<?= $item_id ?>]" type="checkbox" value="1" class="w-3.5 h-3.5 text-blue-600 bg-slate-800 border-slate-600 rounded">
                            <span><?= htmlspecialchars($item) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 5. Timeline Tanggal -->
        <div class="form-card">
            <div class="form-card-title">
                <svg class="w-3.5 h-3.5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <span>Timeline & Target Selesai</span>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label for="request_date" class="form-micro-label">Request Date</label>
                    <input type="date" id="request_date" name="request_date" class="compact-input">
                </div>
                <div>
                    <label for="deadline" class="form-micro-label">Deadline</label>
                    <input type="date" id="deadline" name="deadline" class="compact-input">
                </div>
                <div>
                    <label for="sign_off_date" class="form-micro-label">Sign-Off Date</label>
                    <input type="date" id="sign_off_date" name="sign_off_date" class="compact-input">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2 pt-1">
                <div>
                    <label for="submission_date" class="form-micro-label">Submission Date</label>
                    <input type="date" id="submission_date" name="submission_date" class="compact-input">
                </div>
                <div>
                    <label for="approved_date" class="form-micro-label">Approved Date</label>
                    <input type="date" id="approved_date" name="approved_date" class="compact-input">
                </div>
            </div>
        </div>

        <!-- 6. Metadata Submission & Review -->
        <div class="form-card">
            <div class="form-card-title">
                <svg class="w-3.5 h-3.5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <span>Submission & Reviewer</span>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label for="base_submission_id" class="form-micro-label">Base Sub ID</label>
                    <input type="text" id="base_submission_id" name="base_submission_id" class="compact-input font-mono text-[11px]" placeholder="Base ID">
                </div>
                <div>
                    <label for="submission_id" class="form-micro-label">Submission ID</label>
                    <input type="text" id="submission_id" name="submission_id" class="compact-input font-mono text-[11px]" placeholder="Sub ID">
                </div>
                <div>
                    <label for="reviewer_email" class="form-micro-label">Reviewer Email</label>
                    <input type="email" id="reviewer_email" name="reviewer_email" class="compact-input text-[11px]" placeholder="reviewer@...">
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modelNameInput = document.getElementById('model_name');
    const projectNameInput = document.getElementById('project_name');
    const requestDateInput = document.getElementById('request_date');
    const deadlineInput = document.getElementById('deadline');
    const signOffDateInput = document.getElementById('sign_off_date');

    if (modelNameInput && projectNameInput) {
        modelNameInput.addEventListener('change', function() {
            const modelName = this.value.trim();
            if (modelName.length > 3) {
                projectNameInput.value = 'Mencari...';
                fetch(`get_marketing_name.php?model_name=${encodeURIComponent(modelName)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.is_dropped) {
                            modelNameInput.dataset.isDropped = "1";
                            modelNameInput.classList.add('border-rose-500', 'text-rose-400');
                            projectNameInput.value = '';
                            projectNameInput.placeholder = 'Model sudah Discontinue / Drop';
                            if (typeof showDroppedModelModal === 'function') {
                                showDroppedModelModal(modelName);
                            } else {
                                alert(`Task untuk model ${modelName} tidak dapat disimpan karena sudah Discontinue / Drop dari proses development.`);
                            }
                            return;
                        } else {
                            delete modelNameInput.dataset.isDropped;
                            modelNameInput.classList.remove('border-rose-500', 'text-rose-400');
                        }

                        if (data.success && data.marketing_name) {
                            projectNameInput.value = data.marketing_name;
                        } else {
                            projectNameInput.value = '';
                            projectNameInput.placeholder = 'Nama pemasaran tidak ditemukan';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        projectNameInput.value = '';
                        projectNameInput.placeholder = 'Gagal mengambil data';
                    });
            }
        });
    }

    // Intercept form submit jika model yang diinput drop
    const taskForms = document.querySelectorAll('form[action="handler.php"]');
    taskForms.forEach(f => {
        f.addEventListener('submit', function(e) {
            const mInput = f.querySelector('#model_name');
            if (mInput && mInput.dataset.isDropped === "1") {
                e.preventDefault();
                e.stopPropagation();
                if (typeof showDroppedModelModal === 'function') {
                    showDroppedModelModal(mInput.value.trim());
                } else {
                    alert(`Task untuk model ${mInput.value} tidak dapat disimpan karena sudah Discontinue / Drop.`);
                }
                return false;
            }
        });
    });

    function calculateWorkingDays(startDate, daysToAdd) {
        let currentDate = new Date(startDate);
        let addedDays = 0;
        while (addedDays < daysToAdd) {
            currentDate.setDate(currentDate.getDate() + 1);
            if (currentDate.getDay() !== 0 && currentDate.getDay() !== 6) {
                addedDays++;
            }
        }
        return currentDate.toISOString().slice(0, 10);
    }
    
    function setDefaultDates() {
        const today = new Date();
        const todayString = today.toISOString().slice(0, 10);
        
        const taskIdInput = document.getElementById('task-id');
        if (!taskIdInput || !taskIdInput.value) {
            if (requestDateInput) requestDateInput.value = todayString;
            
            const futureDate = calculateWorkingDays(todayString, 7);
            if (deadlineInput) deadlineInput.value = futureDate;
            if (signOffDateInput) signOffDateInput.value = futureDate;
        }
    }

    setDefaultDates();

    if (requestDateInput && deadlineInput && signOffDateInput) {
        requestDateInput.addEventListener('change', function() {
            if (this.value) {
                const futureDate = calculateWorkingDays(this.value, 7);
                deadlineInput.value = futureDate;
                signOffDateInput.value = futureDate;
            }
        });
    }
});
</script>