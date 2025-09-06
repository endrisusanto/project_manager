<!-- Isian form ini akan digunakan baik untuk menambah maupun mengedit proyek -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label for="project_name" class="form-label block mb-1 text-sm font-medium">Nama Proyek</label>
        <input type="text" id="project_name" name="project_name" required class="themed-input block w-full text-sm rounded-lg p-2.5">
    </div>
    <div>
        <label for="product_model" class="form-label block mb-1 text-sm font-medium">Model Produk</label>
        <input type="text" id="product_model" name="product_model" required class="themed-input block w-full text-sm rounded-lg p-2.5">
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div>
        <label for="project_type" class="form-label block mb-1 text-sm font-medium">Tipe Proyek</label>
        <select id="project_type" name="project_type" required class="themed-input block w-full text-sm rounded-lg p-2.5">
            <option>New Launch</option>
            <option>Maintenance Release</option>
            <option>Security Release</option>
        </select>
    </div>
    <div>
        <label for="status" class="form-label block mb-1 text-sm font-medium">Status</label>
        <select id="status" name="status" required class="themed-input block w-full text-sm rounded-lg p-2.5">
            <option>Planning</option>
            <option>In Development</option>
            <option>GBA Testing</option>
            <option>Released</option>
            <option>Software Confirm / FOTA</option>
        </select>
    </div>
    <div>
        <label for="due_date" class="form-label block mb-1 text-sm font-medium">Tenggat Waktu</label>
        <input type="date" id="due_date" name="due_date" class="themed-input block w-full text-sm rounded-lg p-2.5">
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
     <div>
        <label for="ap" class="form-label block mb-1 text-sm font-medium">AP Version</label>
        <input type="text" id="ap" name="ap" class="themed-input block w-full text-sm rounded-lg p-2.5">
    </div>
     <div>
        <label for="cp" class="form-label block mb-1 text-sm font-medium">CP Version</label>
        <input type="text" id="cp" name="cp" class="themed-input block w-full text-sm rounded-lg p-2.5">
    </div>
     <div>
        <label for="csc" class="form-label block mb-1 text-sm font-medium">CSC Version</label>
        <input type="text" id="csc" name="csc" class="themed-input block w-full text-sm rounded-lg p-2.5">
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label for="qb_user" class="form-label block mb-1 text-sm font-medium">QB User Build ID</label>
        <div class="relative">
            <input type="text" id="qb_user" name="qb_user" class="themed-input block w-full text-sm rounded-lg p-2.5 pr-10">
            <button type="button" onclick="copyQbLink(this, 'qb_user')" class="absolute inset-y-0 right-0 flex items-center pr-3 text-icon hover:text-accent-color">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M7 9a2 2 0 012-2h6a2 2 0 012 2v6a2 2 0 01-2 2H9a2 2 0 01-2-2V9z"></path><path d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V5a2 2 0 00-2-2H4zm0 2h10v10H4V5z"></path></svg>
            </button>
        </div>
    </div>
    <div>
        <label for="qb_userdebug" class="form-label block mb-1 text-sm font-medium">QB Userdebug Build ID</label>
        <div class="relative">
            <input type="text" id="qb_userdebug" name="qb_userdebug" class="themed-input block w-full text-sm rounded-lg p-2.5 pr-10">
            <button type="button" onclick="copyQbLink(this, 'qb_userdebug')" class="absolute inset-y-0 right-0 flex items-center pr-3 text-icon hover:text-accent-color">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M7 9a2 2 0 012-2h6a2 2 0 012 2v6a2 2 0 01-2 2H9a2 2 0 01-2-2V9z"></path><path d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V5a2 2 0 00-2-2H4zm0 2h10v10H4V5z"></path></svg>
            </button>
        </div>
    </div>
</div>


<div>
    <label for="description" class="form-label block mb-1 text-sm font-medium">Deskripsi</label>
    <textarea id="description" name="description" rows="4" class="themed-input block w-full text-sm rounded-lg p-2.5"></textarea>
</div>

