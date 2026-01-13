<?php

namespace YourName\CrmGenerator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MakeCrm extends Command
{
    protected $signature = 'make:crm {name}';
    protected $description = 'Generate a CRM module with migration, model, controller, views, and admin menu with multiple fields';

    public function handle()
    {
        $name = ucfirst($this->argument('name'));
        $model = $name;
        $table = Str::plural(Str::snake($name));
        $controller = "{$name}Controller";
        $routeParam = Str::camel($model);

        $this->info("Generating CRM module [$name]...");

        // Ask for fields
        $fieldCount = (int)$this->ask("How many fields (excluding 'id' and timestamps')?");
        $fields = [];
        for ($i = 1; $i <= $fieldCount; $i++) {
            $fieldName = $this->ask("Field #$i name");
            $fieldType = $this->choice("Field #$i type", ['string','text','integer','boolean','date','datetime'], 0);
            $fields[$fieldName] = $fieldType;
        }

        // Ensure at least one field
        if (empty($fields)) {
            $this->warn("No fields provided. Adding default 'name' field.");
            $fields['name'] = 'string';
        }

        // 1️⃣ AdminMenu table
        if (!Schema::hasTable('admin_menus')) {
            $this->createAdminMenuTable();
        }

        // 2️⃣ Model + Migration
        $this->createModuleModel($model, array_keys($fields));
        $this->createModuleMigration($table, $fields);

        // 3️⃣ Run migrations
        $this->info("Running migrations...");
        Artisan::call('migrate', ['--force' => true]);
        $this->info(Artisan::output());

        // 4️⃣ Add admin menu entry
        if (Schema::hasTable('admin_menus')) {
            DB::table('admin_menus')->insertOrIgnore([
                'slug' => $table,
                'label' => $name,
                'order_no' => DB::table('admin_menus')->max('order_no') + 1,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("Admin menu [$name] added ✅");
        }

        // 5️⃣ Controller
        $this->createController($controller, $model, $table, $routeParam, array_keys($fields));

        // 6️⃣ Routes
        File::append(base_path('routes/web.php'), "\nRoute::resource('admin/$table', \\App\\Http\\Controllers\\Admin\\$controller::class)->names('admin.$table');\n");

        // 7️⃣ Views
        $viewDir = resource_path("views/admin/$table");
        File::ensureDirectoryExists($viewDir);
        $this->generateViews($viewDir, $table, $routeParam, $fields);

        // 8️⃣ Layout
        $this->createLayout();

        $this->info("CRM module [$name] generated successfully ✅");
    }

    protected function createAdminMenuTable()
    {
        $this->callSilent('make:model', ['name' => 'AdminMenu', '-m' => true]);
        $migration = collect(File::files(database_path('migrations')))
            ->last(fn($f) => str_contains($f->getFilename(), 'create_admin_menus_table'));
        File::put($migration->getPathname(), <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('admin_menus', function (Blueprint \$table) {
            \$table->id();
            \$table->string('slug')->unique();
            \$table->string('label');
            \$table->integer('order_no')->default(0);
            \$table->boolean('is_active')->default(true);
            \$table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('admin_menus');
    }
};
PHP
        );

        File::put(app_path('Models/AdminMenu.php'), <<<PHP
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AdminMenu extends Model {
    protected \$fillable = ['slug','label','order_no','is_active'];
}
PHP
        );
    }

    protected function createModuleModel($model, $fields)
    {
        $this->callSilent('make:model', ['name' => $model, '-m' => true]);
        $fillable = implode("','", $fields);
        File::put(app_path("Models/$model.php"), <<<PHP
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class $model extends Model {
    protected \$fillable = ['$fillable'];
}
PHP
        );
    }

    protected function createModuleMigration($table, $fields)
    {
        $this->callSilent('make:migration', ['name' => "create_{$table}_table"]);
        $migration = collect(File::files(database_path('migrations')))
            ->last(fn($f) => str_contains($f->getFilename(), "create_{$table}_table"));

        $fieldLines = '';
        foreach ($fields as $name => $type) {
            $fieldLines .= "\$table->$type('$name');\n            ";
        }

        File::put($migration->getPathname(), <<<PHP
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('$table', function (Blueprint \$table) {
            \$table->id();
            $fieldLines
            \$table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('$table');
    }
};
PHP
        );
    }

    protected function createController($controller, $model, $table, $routeParam, $fields)
    {
        $fillable = implode("','", $fields);
        File::ensureDirectoryExists(app_path('Http/Controllers/Admin'));
        File::put(app_path("Http/Controllers/Admin/$controller.php"), <<<PHP
<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\\$model;
use Illuminate\Http\Request;

class $controller extends Controller
{
    public function index() {
        \$items = $model::latest()->paginate(10);
        return view('admin.$table.index', compact('items'));
    }

    public function create() {
        return view('admin.$table.create');
    }

    public function store(Request \$request) {
        \$request->validate([ '$fillable' => 'required' ]);
        $model::create(\$request->only('$fillable'));
        return redirect()->route('admin.$table.index');
    }

    public function edit($model \${$routeParam}) {
        return view('admin.$table.edit', ['$routeParam' => \${$routeParam}]);
    }

    public function update(Request \$request, $model \${$routeParam}) {
        \$request->validate([ '$fillable' => 'required' ]);
        \${$routeParam}->update(\$request->only('$fillable'));
        return redirect()->route('admin.$table.index');
    }

    public function destroy($model \${$routeParam}) {
        \${$routeParam}->delete();
        return back();
    }
}
PHP
        );
    }

    protected function generateViews($dir, $table, $routeParam, $fields)
    {
        // ---------- INDEX ----------
        $tableHeaders = '';
        $tableRows = '';

        foreach ($fields as $f => $type) {
            $label = Str::title(str_replace('_', ' ', $f));
            $tableHeaders .= "<th>$label</th>\n";
            $tableRows .= "<td>{{ \${$routeParam}->$f }}</td>\n";
        }

        File::put("$dir/index.blade.php", <<<BLADE
    @extends('layouts.admin')
    @section('content')
    <a href="{{ route('admin.$table.create') }}" class="btn btn-primary mb-2">Add</a>

    @if(\$items->count())
    <table class="table table-bordered">
    <thead>
    <tr>
    $tableHeaders
    <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    @foreach(\$items as \${$routeParam})
    <tr>
    $tableRows
    <td>
    <a href="{{ route('admin.$table.edit', \${$routeParam}) }}" class="btn btn-sm btn-warning">Edit</a>
    <form action="{{ route('admin.$table.destroy', \${$routeParam}) }}" method="POST" style="display:inline">
    @csrf
    @method('DELETE')
    <button class="btn btn-sm btn-danger">Delete</button>
    </form>
    </td>
    </tr>
    @endforeach
    </tbody>
    </table>

    {{ \$items->links() }}
    @else
    <div class="alert alert-info">No records found.</div>
    @endif
    @endsection
    BLADE
        );

        // ---------- CREATE & EDIT ----------
        $inputs = '';
        foreach ($fields as $f => $type) {
            $label = Str::title(str_replace('_', ' ', $f));
            $inputs .= <<<HTML
    <div class="mb-3">
        <label for="$f" class="form-label">$label</label>
        <input
            type="text"
            id="$f"
            name="$f"
            value="{{ \${$routeParam}->$f ?? '' }}"
            class="form-control"
        >
    </div>

    HTML;
        }

        File::put("$dir/create.blade.php", <<<BLADE
    @extends('layouts.admin')
    @section('content')
    <form method="POST" action="{{ route('admin.$table.store') }}">
    @csrf
    $inputs
    <button class="btn btn-success">Save</button>
    </form>
    @endsection
    BLADE
        );

        File::put("$dir/edit.blade.php", <<<BLADE
    @extends('layouts.admin')
    @section('content')
    <form method="POST" action="{{ route('admin.$table.update', \${$routeParam}) }}">
    @csrf
    @method('PUT')
    $inputs
    <button class="btn btn-success">Update</button>
    </form>
    @endsection
    BLADE
        );
    }


    protected function createLayout()
    {
        File::ensureDirectoryExists(resource_path('views/layouts'));
        if (!File::exists(resource_path('views/layouts/admin.blade.php'))) {
            File::put(resource_path('views/layouts/admin.blade.php'), <<<'BLADE'
<!doctype html>
<html>
<head>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="d-flex">
<div class="bg-dark text-white p-3" style="width:200px">
<ul class="nav flex-column">
@foreach(\Illuminate\Support\Facades\DB::table('admin_menus')->where('is_active',1)->orderBy('order_no')->get() as $menu)
<li class="nav-item">
<a class="nav-link text-white" href="{{ url('/admin/'.$menu->slug) }}">
{{ $menu->label }}
</a>
</li>
@endforeach
</ul>
</div>
<div class="p-4 w-100">
@yield('content')
</div>
</div>
</body>
</html>
BLADE
            );
        }
    }
}
