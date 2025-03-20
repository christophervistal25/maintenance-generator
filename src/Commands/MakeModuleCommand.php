<?php

namespace DevKits\ModuleGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;

class MakeModuleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:module {name : The name of the module}
                           {--fields= : The fields for the migration and model}
                           {--selects= : Define select fields with options (format: field:option1,option2,option3)}
                           {--path= : The path where the module will be created}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new module with controller, model and migration';

    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * Create a new command instance.
     *
     * @param \Illuminate\Filesystem\Filesystem $files
     * @return void
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $name = $this->argument('name');
        $fields = $this->option('fields');
        $selects = $this->option('selects');
        $path = $this->option('path');

        // Normalize the module name
        $moduleName = Str::studly($name);
        $tableName = Str::plural(Str::snake($name));

        // Process select options
        $selectFields = [];
        if (!empty($selects)) {
            $selectOptions = explode(';', $selects);
            foreach ($selectOptions as $option) {
                list($field, $values) = explode(':', $option, 2);
                $selectFields[$field] = explode(',', $values);
            }
        }

        $this->info("Creating {$moduleName} module...");

        // Create the model
        $this->createModel($moduleName, $fields, $selectFields);

        // Create the migration
        $this->createMigration($tableName, $fields, $selectFields);

        // Create the controller
        $this->createController($moduleName, $selectFields);

        // Create validation rules and form view if selects defined
        if (!empty($selectFields)) {
            $this->info("Adding select field options...");
        }

        $this->info("{$moduleName} module created successfully!");

        return 0;
    }

    /**
     * Create a model file for the module.
     *
     * @param string $name
     * @param string|null $fields
     * @param array $selectFields
     * @return void
     */
    protected function createModel($name, $fields = null, array $selectFields = [])
    {
        $this->info("Creating Model: {$name}");

        // Get the stub path
        $stubPath = $this->getStubPath('model.stub');

        // Replace the placeholders
        $modelStub = $this->files->get($stubPath);
        $modelStub = str_replace('{{modelName}}', $name, $modelStub);

        // Add fillable fields if specified
        $fillableFields = '';
        if (!empty($fields)) {
            $fieldArray = explode(',', $fields);
            $fillableArray = [];

            foreach ($fieldArray as $field) {
                // Extract field name (remove type definition if present)
                $fieldName = trim(explode(':', $field)[0]);
                $fillableArray[] = "'{$fieldName}'";
            }

            $fillableFields = implode(', ', $fillableArray);
        }

        // Add select fields to fillable if they're not already there
        foreach (array_keys($selectFields) as $selectField) {
            if (!empty($fillableFields) && !str_contains($fillableFields, "'{$selectField}'")) {
                $fillableFields .= ", '{$selectField}'";
            } elseif (empty($fillableFields)) {
                $fillableFields = "'{$selectField}'";
            }
        }

        // Replace the fillable property first
        $modelStub = str_replace('{{fillable}}', $fillableFields, $modelStub);

        // Add select options as constants to the model
        if (!empty($selectFields)) {
            $selectConstants = "\n    // Select options for fields\n";
            foreach ($selectFields as $field => $options) {
                $constantName = strtoupper($field) . '_OPTIONS';
                $optionsArray = array_map(function ($opt) {
                    return "'" . trim($opt) . "'";
                }, $options);
                $selectConstants .= "    public const {$constantName} = [" . implode(', ', $optionsArray) . "];\n";
            }

            // Insert the constants after the fillable property using regex for more reliable matching
            $pattern = '/protected \$fillable = \[.*?\];/s';
            $replacement = "$0\n$selectConstants";
            $modelStub = preg_replace($pattern, $replacement, $modelStub);
        }

        // Determine the model path
        $path = app_path('Models/' . $name . '.php');

        // Create the directory if it doesn't exist
        $this->makeDirectory(dirname($path));

        // Create the file
        $this->files->put($path, $modelStub);

        $this->info("Model created: {$path}");
    }

    /**
     * Create a migration file for the module.
     *
     * @param string $tableName
     * @param string|null $fields
     * @param array $selectFields
     * @return void
     */
    protected function createMigration($tableName, $fields = null, array $selectFields = [])
    {
        $this->info("Creating Migration for table: {$tableName}");

        // Generate a migration filename
        $timestamp = date('Y_m_d_His');
        $migrationName = $timestamp . '_create_' . $tableName . '_table';

        // Get the stub path
        $stubPath = $this->getStubPath('migration.stub');

        // Replace the placeholders
        $migrationStub = $this->files->get($stubPath);
        $migrationStub = str_replace('{{table}}', $tableName, $migrationStub);

        // Process fields for migration
        $schemaFields = '$table->id();' . PHP_EOL;

        if (!empty($fields)) {
            $fieldArray = explode(',', $fields);

            foreach ($fieldArray as $field) {
                $field = trim($field);
                $parts = explode(':', $field);

                $fieldName = trim($parts[0]);
                $fieldType = isset($parts[1]) ? trim($parts[1]) : 'string';

                // Skip if this is a select field (we'll handle these separately)
                if (!array_key_exists($fieldName, $selectFields)) {
                    $schemaFields .= '            $table->' . $fieldType . '(\'' . $fieldName . '\');' . PHP_EOL;
                }
            }
        }

        // Add select fields as enum columns
        foreach ($selectFields as $field => $options) {
            // Check if Laravel version supports native enum
            // For Laravel 8+ we can use enum directly
            $schemaFields .= '            $table->enum(\'' . $field . '\', [';
            $enumOptions = [];
            foreach ($options as $option) {
                $enumOptions[] = "'" . trim($option) . "'";
            }
            $schemaFields .= implode(', ', $enumOptions) . ']);' . PHP_EOL;
        }

        $schemaFields .= '            $table->timestamps();';

        $migrationStub = str_replace('{{schema_up}}', $schemaFields, $migrationStub);

        // Determine the migration path
        $path = database_path('migrations/' . $migrationName . '.php');

        // Create the file
        $this->files->put($path, $migrationStub);

        $this->info("Migration created: {$path}");
    }

    /**
     * Create a controller file for the module.
     *
     * @param string $name
     * @param array $selectFields
     * @return void
     */
    protected function createController($name, array $selectFields = [])
    {
        $this->info("Creating Controller: {$name}Controller");

        // Get the stub path
        $stubPath = $this->getStubPath('controller.stub');

        // Replace the placeholders
        $controllerStub = $this->files->get($stubPath);
        $controllerStub = str_replace('{{controllerName}}', $name . 'Controller', $controllerStub);
        $controllerStub = str_replace('{{modelName}}', $name, $controllerStub);
        $modelVariable = lcfirst($name);
        $controllerStub = str_replace('{{modelVariable}}', $modelVariable, $controllerStub);

        // Add select field options for create and edit methods
        if (!empty($selectFields)) {
            // Define the code to add to the create method
            $createSelectCode = "\n        // Select options for dropdown fields\n";
            foreach ($selectFields as $field => $options) {
                $createSelectCode .= "        \${$field}Options = {$name}::" . strtoupper($field) . "_OPTIONS;\n";
            }

            // Insert into create method using regex for more reliable matching
            $pattern = '/public function create\(\)\s*\{\s*/';
            $replacement = "$0" . $createSelectCode . "\n";
            $controllerStub = preg_replace($pattern, $replacement, $controllerStub);

            // Modify the return statement to include options
            $pattern = '/return view\(\'{{modelVariable}}\.create\'\);/';
            $replacement = "return view('{{modelVariable}}.create', [" . $this->buildSelectViewParams($selectFields) . "]);";
            $controllerStub = preg_replace($pattern, $replacement, $controllerStub);

            // Do the same for the edit method
            $pattern = '/public function edit\({{modelName}} \${{modelVariable}}\)\s*\{\s*/';
            $replacement = "$0" . $createSelectCode . "\n";
            $controllerStub = preg_replace($pattern, $replacement, $controllerStub);

            // Modify the return statement for edit
            $pattern = '/return view\(\'{{modelVariable}}\.edit\', compact\(\'{{modelVariable}}\'\)\);/';
            $replacement = "return view('{{modelVariable}}.edit', compact('{{modelVariable}}', " .
                $this->buildSelectViewParamsCompact($selectFields) . "));";
            $controllerStub = preg_replace($pattern, $replacement, $controllerStub);

            // Add validation rules for select fields
            $validationCode = "";
            foreach (array_keys($selectFields) as $field) {
                $validationCode .= "            '{$field}' => 'required|in:' . implode(',', {$name}::" . strtoupper($field) . "_OPTIONS),\n";
            }

            // Insert into store and update methods
            $pattern = '/\$validated = \$request->validate\(\[\s*\/\/ Add validation rules here\s*\]\);/';
            $replacement = "\$validated = \$request->validate([\n            // Add validation rules here\n{$validationCode}        ]);";
            $controllerStub = preg_replace($pattern, $replacement, $controllerStub);
        }

        // Determine the controller path
        $path = app_path('Http/Controllers/' . $name . 'Controller.php');

        // Create the directory if it doesn't exist
        $this->makeDirectory(dirname($path));

        // Create the file
        $this->files->put($path, $controllerStub);

        $this->info("Controller created: {$path}");
    }

    /**
     * Build view parameters for select fields.
     *
     * @param array $selectFields
     * @return string
     */
    private function buildSelectViewParams(array $selectFields)
    {
        $params = [];
        foreach (array_keys($selectFields) as $field) {
            $params[] = "'{$field}Options' => \${$field}Options";
        }
        return implode(', ', $params);
    }

    /**
     * Build compact parameters for select fields.
     *
     * @param array $selectFields
     * @return string
     */
    private function buildSelectViewParamsCompact(array $selectFields)
    {
        $params = [];
        foreach (array_keys($selectFields) as $field) {
            $params[] = "'{$field}Options'";
        }
        return implode(', ', $params);
    }

    /**
     * Get the stub file path.
     *
     * @param string $stubName
     * @return string
     */
    protected function getStubPath($stubName)
    {
        $customPath = resource_path('stubs/vendor/module-generator/' . $stubName);

        if ($this->files->exists($customPath)) {
            return $customPath;
        }

        return __DIR__ . '/../Stubs/' . $stubName;
    }

    /**
     * Create the directory for the file if it doesn't exist.
     *
     * @param string $path
     * @return string
     */
    protected function makeDirectory($path)
    {
        if (!$this->files->isDirectory($path)) {
            $this->files->makeDirectory($path, 0755, true, true);
        }

        return $path;
    }
}
