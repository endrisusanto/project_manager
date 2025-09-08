<div class="space-y-4">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="model_name" class="form-label block mb-1 text-sm font-medium">Model Name</label>
            <input type="text" id="model_name" name="model_name" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
        </div>
        <div>
            <label for="pic_email" class="form-label block mb-1 text-sm font-medium">PIC (Email)</label>
            <input type="email" id="pic_email" name="pic_email" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
        </div>
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
            <div class="relative">
                <input type="text" id="qb_user" name="qb_user" class="themed-input w-full p-2.5 text-sm rounded-lg" placeholder="e.g., 1234567">
                <button type="button" onclick="copyQbLink(this, 'qb_user')" class="absolute inset-y-0 right-0 flex items-center pr-3 text-icon hover:text-blue-400">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M7 9a2 2 0 012-2h6a2 2 0 012 2v6a2 2 0 01-2 2H9a2 2 0 01-2-2V9z"></path><path d="M5 3a2 2 0 00-2 2v6a2 2 0 002 2V5h6a2 2 0 00-2-2H5z"></path></svg>
                </button>
            </div>
        </div>
        <div>
            <label for="qb_eng" class="form-label block mb-1 text-sm font-medium">QB ENG</label>
            <div class="relative">
                <input type="text" id="qb_eng" name="qb_eng" class="themed-input w-full p-2.5 text-sm rounded-lg" placeholder="e.g., 1234568">
                <button type="button" onclick="copyQbLink(this, 'qb_eng')" class="absolute inset-y-0 right-0 flex items-center pr-3 text-icon hover:text-blue-400">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M7 9a2 2 0 012-2h6a2 2 0 012 2v6a2 2 0 01-2 2H9a2 2 0 01-2-2V9z"></path><path d="M5 3a2 2 0 00-2 2v6a2 2 0 002 2V5h6a2 2 0 00-2-2H5z"></path></svg>
                </button>
            </div>
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
            <select id="progress_status" name="progress_status" class="themed-input w-full p-2.5 text-sm rounded-lg" required>
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
            <input type="date" id="submission_date" name="submission_date" class="themed-input w-full p-2 text-sm rounded-lg highlight-important">
        </div>
        <div>
            <label for="approved_date" class="form-label block mb-1 text-sm font-medium">Approved Date</label>
            <input type="date" id="approved_date" name="approved_date" class="themed-input w-full p-2 text-sm rounded-lg highlight-important">
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
            <input type="text" id="submission_id" name="submission_id" class="themed-input w-full p-2.5 text-sm rounded-lg highlight-important">
        </div>
        <div>
            <label for="reviewer_email" class="form-label block mb-1 text-sm font-medium">Reviewer Email</label>
            <input type="email" id="reviewer_email" name="reviewer_email" class="themed-input w-full p-2.5 text-sm rounded-lg highlight-important">
        </div>
    </div>
    
    <div>
        <label class="form-label block mb-1 text-sm font-medium">Test Items Checklist</label>
        <div class="glassmorphism-table p-4 rounded-lg">
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
                        <input id="checklist_<?= $plan_id ?>_<?= $item_id ?>" name="checklist[<?= $item_id ?>]" type="checkbox" value="1" class="w-4 h-4 text-blue-600 bg-gray-700 border-gray-600 rounded focus:ring-blue-600 ring-offset-gray-800 focus:ring-2">
                        <label for="checklist_<?= $plan_id ?>_<?= $item_id ?>" class="ml-2 text-sm text-primary"><?= htmlspecialchars($item) ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div>
        <label for="notes-editor" class="form-label block mb-1 text-sm font-medium">Notes</label>
        <input type="hidden" name="notes" id="notes-hidden-input">
        <div id="notes-editor" class="themed-input rounded-lg"></div>
    </div>
</div>