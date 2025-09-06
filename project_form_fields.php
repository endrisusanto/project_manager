<div class="space-y-4">
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

    <div>
        <label for="project_type" class="form-label block mb-1 text-sm font-medium">Tipe Proyek</label>
        <select id="project_type" name="project_type" required class="themed-input block w-full text-sm rounded-lg p-2.5">
            <option>New Launch</option>
            <option>Maintenance Release</option>
            <option>Security Release</option>
        </select>
    </div>
    
    <input type="hidden" id="status" name="status" value="Planning">

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
                <button type="button" onclick="copyQbLink(this, 'qb_user')" class="absolute inset-y-0 right-0 flex items-center pr-3 text-icon hover:text-blue-400">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M7 9a2 2 0 012-2h6a2 2 0 012 2v6a2 2 0 01-2 2H9a2 2 0 01-2-2V9z"></path><path d="M5 3a2 2 0 00-2 2v6a2 2 0 002 2V5h6a2 2 0 00-2-2H5z"></path></svg>
                </button>
            </div>
        </div>
        <div>
            <label for="qb_userdebug" class="form-label block mb-1 text-sm font-medium">QB Userdebug Build ID</label>
            <div class="relative">
                <input type="text" id="qb_userdebug" name="qb_userdebug" class="themed-input block w-full text-sm rounded-lg p-2.5 pr-10">
                <button type="button" onclick="copyQbLink(this, 'qb_userdebug')" class="absolute inset-y-0 right-0 flex items-center pr-3 text-icon hover:text-blue-400">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M7 9a2 2 0 012-2h6a2 2 0 012 2v6a2 2 0 01-2 2H9a2 2 0 01-2-2V9z"></path><path d="M5 3a2 2 0 00-2 2v6a2 2 0 002 2V5h6a2 2 0 00-2-2H5z"></path></svg>
                </button>
            </div>
        </div>
    </div>
    
    <div>
        <label for="description" class="form-label block mb-1 text-sm font-medium">Deskripsi</label>
        <textarea id="description" name="description" rows="3" class="themed-input block w-full text-sm rounded-lg p-2.5"></textarea>
    </div>

    <div class="flex items-center space-x-6 pt-2">
        <div class="flex items-center">
            <input id="software_released" name="software_released" type="checkbox" value="1" class="w-4 h-4 text-blue-600 bg-gray-700 border-gray-600 rounded focus:ring-blue-600 ring-offset-gray-800 focus:ring-2">
            <label for="software_released" class="ml-2 text-sm font-medium text-primary">Software sudah dirilis?</label>
        </div>
        <div class="flex items-center">
            <input id="use_gba_testing" name="use_gba_testing" type="checkbox" value="1" class="w-4 h-4 text-blue-600 bg-gray-700 border-gray-600 rounded focus:ring-blue-600 ring-offset-gray-800 focus:ring-2">
            <label for="use_gba_testing" class="ml-2 text-sm font-medium text-primary">Dipakai untuk GBA Testing?</label>
        </div>
    </div>
</div>