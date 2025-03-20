# Laravel Module Generator

A powerful Laravel package to generate a complete module (Controller, Model, and Migration) with a single artisan command. Streamline your development workflow and maintain consistency across your application.

## Installation

You can install the package via composer:

```bash
composer require devkits/module-generator
```

## Auto-Discovery

This package supports Laravel's auto-discovery feature. After you install it, the service provider will be automatically registered, and you can start using the package right away without any additional configuration.

For Laravel versions before 5.5 (which is unlikely since this package requires Laravel 8.0+), you would need to manually add the service provider to your `config/app.php` file:

```php
'providers' => [
    // Other service providers...
    DevKits\ModuleGenerator\ServiceProvider::class,
],
```

## Basic Usage

```bash
php artisan make:module Blog
```

This simple command will create:

- A `Blog` model in `app/Models/`
- A `BlogController` in `app/Http/Controllers/`
- A migration file for the `blogs` table
- A factory file for the model

## Advanced Usage

### Field Generation

Generate a module with specific fields:

```bash
php artisan make:module Product --fields="name:string,price:decimal,description:text,in_stock:boolean"
```

This will create:

- A `Product` model with `name`, `price`, `description`, and `in_stock` as fillable fields
- A migration with the appropriate columns and types
- A `ProductController` with CRUD methods
- A factory with appropriate faker methods for each field type

### Select/Dropdown Fields

Define fields that should be rendered as select/dropdown menus with predefined options:

```bash
php artisan make:module Employee --fields="name:string,email:string,hire_date:date" --selects="department:engineering,marketing,sales,hr;status:active,on_leave,terminated"
```

This will:

- Add `department` and `status` as enum fields in the migration
- Add constants in the model for the dropdown options
- Add validation rules in the controller
- Prepare the controller to pass options to views

The generated model will include constants for the select options:

```php
class Employee extends Model
{
    // ...

    // Select options for fields
    public const DEPARTMENT_OPTIONS = ['engineering', 'marketing', 'sales', 'hr'];
    public const STATUS_OPTIONS = ['active', 'on_leave', 'terminated'];
}
```

### API Controllers

Generate API-focused controllers that return JSON responses:

```bash
php artisan make:module User --api
```

Or:

```bash
php artisan make:module User --type=api
```

### Generate Views

```bash
php artisan make:module Comment --views
```

This creates Blade views for:
- Index (listing)
- Create form
- Edit form
- Show/detail page

### Module Directory Structure

Create modules in a dedicated directory structure:

```bash
php artisan make:module Order --module-style
```

This organizes the module in a modular structure:

```
Modules/
  ├── Order/
      ├── Http/
      │   └── Controllers/
      │       └── OrderController.php
      ├── Models/
      │   └── Order.php
      ├── Database/
      │   ├── Migrations/
      │   │   └── xxxx_xx_xx_xxxxxx_create_orders_table.php
      │   └── Factories/
      │       └── OrderFactory.php
      ├── Resources/
      │   └── views/
      │       ├── index.blade.php
      │       ├── create.blade.php
      │       ├── edit.blade.php
      │       └── show.blade.php
      ├── routes.php
      └── OrderServiceProvider.php
```

## Example Scenarios

### 1. Basic CMS Article Module

```bash
php artisan make:module Article --fields="title:string,slug:string,content:text,excerpt:text,published_at:timestamp" --views
```

### 2. E-commerce Product Module

```bash
php artisan make:module Product --fields="name:string,slug:string,sku:string,price:decimal,cost:decimal,quantity:integer,description:text" --selects="status:draft,published,out_of_stock,discontinued;category:electronics,clothing,home,beauty" --views
```

### 3. User Management Module

```bash
php artisan make:module User --fields="name:string,email:string:unique,email_verified_at:timestamp,password:string,remember_token:string" --selects="role:admin,editor,member,guest" --type=api
```

### 4. Task Management Module

```bash
php artisan make:module Task --fields="title:string,description:text,due_date:date,completed_at:timestamp:nullable" --selects="priority:low,medium,high,urgent;status:backlog,todo,in_progress,review,completed" --module-style --views
```

## Working with Generated Files

### Models

The generated models will include fillable fields and constants for select options:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description', 'due_date', 'completed_at', 'priority', 'status'];

    // Select options for fields
    public const PRIORITY_OPTIONS = ['low', 'medium', 'high', 'urgent'];
    public const STATUS_OPTIONS = ['backlog', 'todo', 'in_progress', 'review', 'completed'];
}
```

### Controllers

The generated controllers include validation for select fields and are prepared to pass options to views:

```php
public function create()
{
    // Select options for dropdown fields
    $priorityOptions = Task::PRIORITY_OPTIONS;
    $statusOptions = Task::STATUS_OPTIONS;

    return view('task.create', [
        'priorityOptions' => $priorityOptions,
        'statusOptions' => $statusOptions
    ]);
}

public function store(Request $request)
{
    $validated = $request->validate([
        'title' => 'required|string|max:255',
        'description' => 'nullable|string',
        'due_date' => 'nullable|date',
        'priority' => 'required|in:' . implode(',', Task::PRIORITY_OPTIONS),
        'status' => 'required|in:' . implode(',', Task::STATUS_OPTIONS),
    ]);

    Task::create($validated);

    return redirect()->route('task.index')
        ->with('success', 'Task created successfully.');
}
```

### Views

When using the `--views` option, the package generates view files that include all necessary form fields, including select dropdowns for enum fields:

```html
<div class="form-group mb-3">
    <label for="status">Status</label>
    <select class="form-control @error('status') is-invalid @enderror" id="status" name="status">
        <option value="">Select Status</option>
        @foreach($statusOptions as $option)
            <option value="{{ $option }}" {{ old('status', $task->status ?? '') == $option ? 'selected' : '' }}>
                {{ ucfirst(str_replace('_', ' ', $option)) }}
            </option>
        @endforeach
    </select>
    @error('status')
        <span class="invalid-feedback" role="alert">
            <strong>{{ $message }}</strong>
        </span>
    @enderror
</div>
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=module-generator-config
```

This creates `config/module-generator.php` with options:

```php
return [
    'path' => 'App',
    'generate_views' => false,
    'view_path' => 'resources/views',
    'default_type' => 'full',
    'add_routes' => true,
    'api_version' => 'v1',
];
```

The configuration options allow you to:

- `path`: Define the base path for generated files (default: 'App')
- `generate_views`: Automatically generate views for all modules (default: false)
- `view_path`: Define where views will be created (default: 'resources/views')
- `default_type`: Set the default module generation type (default: 'full')
- `add_routes`: Automatically add routes to route files (default: true)
- `api_version`: Define the API version prefix for API routes (default: 'v1')

You can customize these settings according to your project's needs. For example, if you always want to generate views, you could set `generate_views` to `true` in your configuration, eliminating the need to pass the `--views` flag each time.

## Testing

```bash
composer test
```

## Credits

Christopher Vistal

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
