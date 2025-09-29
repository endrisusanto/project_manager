<?php
// Ambil daftar pengguna untuk dropdown PIC.
// Variabel $conn sudah tersedia dari file yang meng-include file ini (misal: index.php)
$users_result = $conn->query("SELECT email, username FROM users ORDER BY username ASC");
$users_list = [];
if ($users_result && $users_result->num_rows > 0) {
    while($user_row = $users_result->fetch_assoc()) {
        $users_list[] = $user_row;
    }
}
?>
<style>
    @keyframes outline-glow {
        0%, 100% { box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.4); }
        50% { box-shadow: 0 0 10px 4px rgba(59, 130, 246, 0.8); }
    }
    .important-field {
        animation: outline-glow 2s infinite ease-in-out;
    }
</style>

<div class="space-y-4">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label for="project_name" class="form-label block mb-1 text-sm font-medium">Marketing Name</label>
            <input type="text" id="project_name" name="project_name" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
        </div>
        <div>
            <label for="model_name" class="form-label block mb-1 text-sm font-medium">Model Name</label>
            <input type="text" id="model_name" name="model_name" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
        </div>
        <div>
            <label for="pic_email" class="form-label block mb-1 text-sm font-medium">PIC</label>
            <select id="pic_email" name="pic_email" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
                <option value="" disabled selected>Pilih PIC</option>
                <?php foreach ($users_list as $user): ?>
                    <option value="<?= htmlspecialchars($user['email']) ?>">
                        <?= htmlspecialchars($user['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="flex items-center pt-2">
        <label for="is_urgent_toggle" class="relative inline-flex items-center cursor-pointer">
            <input type="hidden" name="is_urgent" value="0">
            <input type="checkbox" value="1" id="is_urgent_toggle" name="is_urgent" class="sr-only peer">
            <div class="w-11 h-6 bg-gray-600 rounded-full peer peer-focus:ring-4 peer-focus:ring-red-800 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-red-600"></div>
            <span class="ml-3 text-sm font-medium text-black-300 peer-checked:text-red-900">
                Tandai sebagai Task Urgent
            </span>
        </label>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label for="ap" class="form-label block mb-1 text-sm font-medium">AP</label>
            <input type="text" id="ap" name="ap" class="themed-input w-full p-2.5 text-sm rounded-lg">
        </div>
        <div>
            <label for="cp" class="form-label block mb-1 text-sm font-medium">CP</label>
            <input type="text" id="cp" name="cp" class="themed-input w-full p-2.5 text-sm rounded-lg">
        </div>
        <div>
            <label for="csc" class="form-label block mb-1 text-sm font-medium">CSC</label>
            <input type="text" id="csc" name="csc" class="themed-input w-full p-2.5 text-sm rounded-lg">
        </div>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="qb_user" class="form-label block mb-1 text-sm font-medium">QB USER</label>
            <input type="text" id="qb_user" name="qb_user" class="themed-input w-full p-2.5 text-sm rounded-lg" placeholder="e.g., 1234567">
        </div>
        <div>
            <label for="qb_userdebug" class="form-label block mb-1 text-sm font-medium">QB USERDEBUG</label>
            <input type="text" id="qb_userdebug" name="qb_userdebug" class="themed-input w-full p-2.5 text-sm rounded-lg" placeholder="e.g., 1234568">
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="test_plan_type" class="form-label block mb-1 text-sm font-medium">Type Test Plan</label>
            <select id="test_plan_type" name="test_plan_type" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
                <option>Regular Variant</option>
                <option>SKU</option>
                <option>Normal MR</option>
                <option>SMR</option>
                <option>Simple Exception MR</option>
            </select>
        </div>
        <div>
            <label for="progress_status" class="form-label block mb-1 text-sm font-medium">Status Progress</label>
            <select id="progress_status" name="progress_status" class="themed-input w-full p-2.5 text-sm rounded-lg important-field" required>
                <option>Task Baru</option>
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

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        <div>
            <label for="request_date" class="form-label block mb-1 text-sm font-medium">Request Date</label>
            <input type="date" id="request_date" name="request_date" class="themed-input w-full p-2 text-sm rounded-lg">
        </div>
        <div>
            <label for="submission_date" class="form-label block mb-1 text-sm font-medium">Submission Date</label>
            <input type="date" id="submission_date" name="submission_date" class="themed-input w-full p-2 text-sm rounded-lg important-field">
        </div>
        <div>
            <label for="approved_date" class="form-label block mb-1 text-sm font-medium">Approved Date</label>
            <input type="date" id="approved_date" name="approved_date" class="themed-input w-full p-2 text-sm rounded-lg important-field">
        </div>
        <div>
            <label for="deadline" class="form-label block mb-1 text-sm font-medium">Deadline</label>
            <input type="date" id="deadline" name="deadline" class="themed-input w-full p-2 text-sm rounded-lg">
        </div>
        <div>
            <label for="sign_off_date" class="form-label block mb-1 text-sm font-medium">Sign-Off Date</label>
            <input type="date" id="sign_off_date" name="sign_off_date" class="themed-input w-full p-2 text-sm rounded-lg">
        </div>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label for="base_submission_id" class="form-label block mb-1 text-sm font-medium">Base Submission ID</label>
            <input type="text" id="base_submission_id" name="base_submission_id" class="themed-input w-full p-2.5 text-sm rounded-lg">
        </div>
        <div>
            <label for="submission_id" class="form-label block mb-1 text-sm font-medium">Submission ID</label>
            <input type="text" id="submission_id" name="submission_id" class="themed-input w-full p-2.5 text-sm rounded-lg important-field">
        </div>
        <div>
            <label for="reviewer_email" class="form-label block mb-1 text-sm font-medium">Reviewer Email</label>
            <input type="email" id="reviewer_email" name="reviewer_email" class="themed-input w-full p-2.5 text-sm rounded-lg important-field">
        </div>
    </div>
    
    <div>
        <label class="form-label block mb-1 text-sm font-medium">Test Items Checklist</label>
        <div class="glass-container p-4 rounded-lg">
            <div id="checklist-placeholder" class="text-sm text-secondary">Pilih Tipe Test Plan untuk melihat checklist.</div>
            
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
            <div id="checklist-container-<?= $plan_id ?>" class="hidden space-y-2">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <?php foreach($items as $item): 
                        $item_id = str_replace([' ', '-'], '_', $item);
                    ?>
                    <div class="flex items-center">
                        <input id="checklist_<?= $plan_id ?>_<?= $item_id ?>" name="checklist[<?= $item_id ?>]" type="checkbox" value="1" class="w-4 h-4 text-blue-600 bg-gray-700 border-gray-600 rounded">
                        <label for="checklist_<?= $plan_id ?>_<?= $item_id ?>" class="ml-2 text-sm text-primary"><?= htmlspecialchars($item) ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div>
        <!-- <label for="notes-editor" class="form-label block mb-1 text-sm font-medium">Notes</label> -->
        <input type="hidden" name="notes" id="notes-hidden-input">
        <div id="notes-editor" class="themed-input rounded-lg"></div>
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
            if (modelName.length > 5) {
                projectNameInput.value = 'Mencari...';
                fetch(`get_marketing_name.php?model_name=${encodeURIComponent(modelName)}`)
                    .then(response => response.json())
                    .then(data => {
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
            requestDateInput.value = todayString;
            
            const futureDate = calculateWorkingDays(todayString, 7);
            deadlineInput.value = futureDate;
            signOffDateInput.value = futureDate;
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