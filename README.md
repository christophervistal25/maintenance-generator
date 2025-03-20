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

- A Blog model in `app/Models/`
- A BlogController in `app/Http/Controllers/`
- A migration file for the blogs table

## Advanced Usage

### Field Generation

Generate a module with specific fields:

```bash
php artisan make:module Product --fields="name:string,price:decimal,description:text,in_stock:boolean"
```

This will create:

- A Product model with name, price, description, and in_stock as fillable fields
- A migration with the appropriate columns and types
- A ProductController with CRUD methods

### Select/Dropdown Fields

Define fields that should be rendered as select/dropdown menus with predefined options:

```bash
php artisan make:module Employee --fields="name:string,email:string,hire_date:date" --selects="department:engineering,marketing,sales,hr;status:active,on_leave,terminated"
```

This will:

- Add department and status as enum fields in the migration
- Add constants in the model for the dropdown options
- Add validation rules in the controller
- Prepare the controller to pass options to views (note: views are not generated automatically)

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

## Example Scenarios

### 1. Basic CMS Article Module

```bash
php artisan make:module Article --fields="title:string,slug:string,content:text,excerpt:text,published_at:timestamp"
```

### 2. E-commerce Product Module

```bash
php artisan make:module Product --fields="name:string,slug:string,sku:string,price:decimal,cost:decimal,quantity:integer,description:text" --selects="status:draft,published,out_of_stock,discontinued;category:electronics,clothing,home,beauty"
```

### 3. User Management Module

```bash
php artisan make:module User --fields="name:string,email:string:unique,email_verified_at:timestamp,password:string,remember_token:string" --selects="role:admin,editor,member,guest"
```

### 4. Task Management Module

```bash
php artisan make:module Task --fields="title:string,description:text,due_date:date,completed_at:timestamp:nullable" --selects="priority:low,medium,high,urgent;status:backlog,todo,in_progress,review,completed"
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

While the package doesn't generate view files, the controllers are set up to work with views that follow Laravel's conventional structure. You'll need to create these view files manually.

Here's an example of how you might create a form that uses the select options passed from the controller:

```blade
<div class="form-group">
    <label for="status">Status</label>
    <select name="status" id="status" class="form-control @error('status') is-invalid @enderror">
        @foreach($statusOptions as $option)
            <option value="{{ $option }}" {{ old('status', $task->status ?? '') == $option ? 'selected' : '' }}>
                {{ ucfirst(str_replace('_', ' ', $option)) }}
            </option>
        @endforeach
    </select>
    @error('status')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
```

## Customizing Stubs

You can publish the stubs to customize them:

```bash
php artisan vendor:publish --tag=module-generator-stubs
```

This will publish the stubs to `resources/stubs/vendor/module-generator/`.

## Testing

```bash
composer test
```

## Credits

Christopher Vistal

## License

The MIT License (MIT). Please see License File for more information.
