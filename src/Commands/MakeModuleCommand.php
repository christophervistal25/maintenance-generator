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
                            {--path= : The path where the module will be created}
                            {--api : Create an API controller instead}
                            {--views : Generate views for the module}
                            {--force : Overwrite existing files}
                            {--module-style : Create in a modular directory structure}
                            {--type= : Module type (full, api, model-migration)}';

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

        // Process command options
        $generateViews = $this->option('views') !== false
            ? $this->option('views')
            : config('module-generator.generate_views', false);

        // If --api flag is provided, set isApi to true
        $isApi = $this->option('api') || $this->option('type') === 'api';
        $moduleStyle = $this->option('module-style');

        // Use passed type or config default
        $type = $this->option('type') ?: config('module-generator.default_type', 'full');

        $force = $this->option('force');

        // Normalize the module name
        $moduleName = Str::studly($name);
        $tableName = Str::plural(Str::snake($name));

        // Process select options
        $selectFields = [];
        if (!empty($selects)) {
            $selectOptions = explode(';', $selects);
            foreach ($selectOptions as $option) {
                if (strpos($option, ':') !== false) {
                    list($field, $values) = explode(':', $option, 2);
                    $selectFields[$field] = explode(',', $values);
                }
            }
        }

        // Start the process with a nice header
        $this->info("Creating {$moduleName} module...");

        // Determine what to generate based on type
        $steps = 1; // Start with 1 for the preparation step

        if ($type === 'full' || $type === 'model-migration') {
            $steps += 3; // Model, migration and factory
        }

        if ($type === 'full' || $type === 'api') {
            $steps += 1; // Controller
        }

        // If --views flag is explicitly provided, or if config says to generate views
        // and we're making a full module, generate views
        if ($generateViews && ($type === 'full' || $this->option('views'))) {
            $steps += 1; // Views
        }

        if ($type === 'full') {
            $steps += 1; // Routes
        }

        // Update the modulePath based on the module-style option
        $modulePath = '';
        if ($moduleStyle) {
            // Create a Modules structure
            $modulePath = base_path('Modules/' . $moduleName);
            $this->makeDirectory($modulePath);
            $this->makeDirectory($modulePath . '/Http/Controllers');
            $this->makeDirectory($modulePath . '/Models');
            $this->makeDirectory($modulePath . '/Database/Migrations');
            $this->makeDirectory($modulePath . '/Resources/views');
        }

        // Show progress bar
        $this->output->progressStart($steps);
        $this->output->progressAdvance(); // Preparation step completed

        // Create the model and migration for full or model-migration types
        if ($type === 'full' || $type === 'model-migration' || $type === 'api') {
            $this->createModel($moduleName, $fields, $selectFields, $moduleStyle);
            $this->output->progressAdvance();

            $this->createMigration($tableName, $fields, $selectFields, $moduleStyle, $moduleName);
            $this->output->progressAdvance();

            $this->createFactory($moduleName, $fields, $selectFields, $moduleStyle);
            $this->output->progressAdvance();
        }

        // Create the controller for full or api types
        if ($type === 'full' || $type === 'api') {
            // If type is 'api', ensure we create an API controller
            $isApiController = $isApi || $type === 'api';
            $this->createController($moduleName, $selectFields, $isApiController, $moduleStyle);
            $this->output->progressAdvance();
        }

        // Create views if requested (either by flag or config) and not API
        // We'll also create views if --views flag is specifically provided
        if (($generateViews && ($type === 'full') && !$isApi) || $this->option('views')) {
            $this->createViews($moduleName, $fields, $selectFields, $moduleStyle);
            $this->output->progressAdvance();
        }

        // Add routes for full CRUD modules or API type
        if ($type === 'full' || $type === 'api') {
            if ($isApi || $type === 'api') {
                $this->addApiRoutes($moduleName, $moduleStyle);
            } else {
                $this->addRoutes($moduleName, $moduleStyle);
            }
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->output->success("{$moduleName} module created successfully!");

        return 0;
    }

    /**
     * Create a model file for the module.
     *
     * @param string $name
     * @param string|null $fields
     * @param array $selectFields
     * @param bool $moduleStyle
     * @return void
     */
    protected function createModel($name, $fields = null, array $selectFields = [], $moduleStyle = false)
    {
        $this->info("Creating Model: {$name}");

        // Get the stub path
        $stubPath = $this->getStubPath('model.stub');

        // Replace the placeholders
        $modelStub = $this->files->get($stubPath);
        $modelStub = str_replace('{{modelName}}', $name, $modelStub);

        // Adjust the namespace if using module style
        if ($moduleStyle) {
            $modelStub = str_replace('namespace App\\Models;', "namespace Modules\\{$name}\\Models;", $modelStub);
        }

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

        // Determine the model path based on module style
        if ($moduleStyle) {
            $path = base_path("Modules/{$name}/Models/{$name}.php");
        } else {
            $path = app_path("Models/{$name}.php");
        }

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
     * @param bool $moduleStyle
     * @param string|null $moduleName
     * @return void
     */
    protected function createMigration($tableName, $fields = null, array $selectFields = [], $moduleStyle = false, $moduleName = null)
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
            $schemaFields .= '            $table->enum(\'' . $field . '\', [';
            $enumOptions = [];
            foreach ($options as $option) {
                $enumOptions[] = "'" . trim($option) . "'";
            }
            $schemaFields .= implode(', ', $enumOptions) . ']);' . PHP_EOL;
        }

        $schemaFields .= '            $table->timestamps();';

        $migrationStub = str_replace('{{schema_up}}', $schemaFields, $migrationStub);

        // Determine the migration path based on module style
        if ($moduleStyle) {
            // Use moduleName passed from the handle method
            $path = base_path("Modules/{$moduleName}/Database/Migrations/{$migrationName}.php");
        } else {
            $path = database_path("migrations/{$migrationName}.php");
        }

        // Create the directory if it doesn't exist
        $this->makeDirectory(dirname($path));

        // Create the file
        $this->files->put($path, $migrationStub);

        $this->info("Migration created: {$path}");
    }

    /**
     * Create a controller file for the module.
     *
     * @param string $name
     * @param array $selectFields
     * @param bool $isApi
     * @param bool $moduleStyle
     * @return void
     */
    protected function createController($name, array $selectFields = [], $isApi = false, $moduleStyle = false)
    {
        $this->info("Creating Controller: {$name}Controller");

        // Get the stub path
        $stubPath = $this->getStubPath($isApi ? 'api-controller.stub' : 'controller.stub');

        // Replace the placeholders
        $controllerStub = $this->files->get($stubPath);
        $controllerStub = str_replace('{{controllerName}}', $name . 'Controller', $controllerStub);
        $controllerStub = str_replace('{{modelName}}', $name, $controllerStub);

        $modelVariable = lcfirst($name);
        $routeName = Str::plural($modelVariable); // Use plural for route names

        $controllerStub = str_replace('{{modelVariable}}', $modelVariable, $controllerStub);

        // Update all route references in the controller to use plural form
        // This handles both placeholder route references and already processed ones
        $controllerStub = str_replace("route('{{modelVariable}}.", "route('{$routeName}.", $controllerStub);
        $controllerStub = str_replace("route('{$modelVariable}.", "route('{$routeName}.", $controllerStub);

        // Redirect routes in store, update and destroy methods
        $controllerStub = str_replace(
            ["redirect()->route('{$modelVariable}.", "redirect()->route('{{modelVariable}}."],
            "redirect()->route('{$routeName}.",
            $controllerStub
        );

        // Adjust the namespace and model import if using module style
        if ($moduleStyle) {
            $controllerStub = str_replace('namespace App\Http\Controllers;', "namespace Modules\\{$name}\\Http\\Controllers;", $controllerStub);
            $controllerStub = str_replace("use App\Models\\{$name};", "use Modules\\{$name}\\Models\\{$name};", $controllerStub);
        }

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
            $pattern = '/return view\(\'.*?\.create\'(?:\s*\)|(?:,\s*\[.*?\]\)));/';
            $viewPrefix = $moduleStyle ? "{$name}::" : "";
            $replacement = "return view('{$viewPrefix}{$modelVariable}.create', [" . $this->buildSelectViewParams($selectFields) . "]);";
            $controllerStub = preg_replace($pattern, $replacement, $controllerStub);

            // Do the same for the edit method
            $pattern = '/public function edit\({{modelName}} \${{modelVariable}}\)\s*\{\s*/';
            $replacement = "$0" . $createSelectCode . "\n";
            $controllerStub = preg_replace($pattern, $replacement, $controllerStub);

            // Modify the return statement for edit
            $pattern = '/return view\(\'.*?\.edit\',\s*compact\(\'{{modelVariable}}\'\)\);/';
            $replacement = "return view('{$viewPrefix}{$modelVariable}.edit', compact('{{modelVariable}}', " .
                $this->buildSelectViewParamsCompact($selectFields) . "));";
            $controllerStub = preg_replace($pattern, $replacement, $controllerStub);

        }

        // Update all view references if using module style
        if ($moduleStyle) {
            $controllerStub = str_replace(
                "return view('{$modelVariable}.",
                "return view('{$name}::{$modelVariable}.",
                $controllerStub
            );
        }

        // Determine the controller path based on module style
        if ($moduleStyle) {
            $path = base_path("Modules/{$name}/Http/Controllers/{$name}Controller.php");
        } else {
            $path = app_path("Http/Controllers/{$name}Controller.php");
        }

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

    /**
     * Create views for the module.
     *
     * @param string $name
     * @param string|null $fields
     * @param array $selectFields
     * @param bool $moduleStyle
     * @return void
     */
    protected function createViews($name, $fields = null, array $selectFields = [], $moduleStyle = false)
    {
        $this->info("Creating views for {$name}...");

        $viewsToCreate = ['index', 'create', 'edit', 'show'];
        $modelVariable = lcfirst($name);
        $routeName = Str::plural($modelVariable); // Use plural for route names

        // Process fields
        $formFields = '';
        $tableColumns = '';
        $tableCells = '';
        $detailRows = '';

        if (!empty($fields)) {
            $fieldArray = explode(',', $fields);

            foreach ($fieldArray as $field) {
                $field = trim($field);
                $parts = explode(':', $field);

                $fieldName = trim($parts[0]);
                $fieldType = isset($parts[1]) ? trim($parts[1]) : 'string';

                // Skip if this is a select field (handled separately)
                if (array_key_exists($fieldName, $selectFields)) {
                    continue;
                }

                // Generate form input based on field type
                $formFields .= $this->generateFormInput($fieldName, $fieldType, $modelVariable);

                // Generate table column for index view
                $tableColumns .= "<th>" . ucfirst($fieldName) . "</th>\n";
                $tableCells .= "<td>{{ \${$modelVariable}->{$fieldName} }}</td>\n";

                // Generate detail rows for show view
                $detailRows .= $this->generateDetailRow($fieldName, $modelVariable);
            }
        }

        // Process select fields
        $selectInputs = '';
        foreach ($selectFields as $field => $options) {
            $selectInputs .= $this->generateSelectInput($field, $options, $modelVariable);

            // Add to table columns and detail rows
            $tableColumns .= "<th>" . ucfirst($field) . "</th>\n";
            $tableCells .= "<td>{{ \${$modelVariable}->{$field} }}</td>\n";
            $detailRows .= $this->generateDetailRow($field, $modelVariable);
        }

        foreach ($viewsToCreate as $view) {
            // Get the stub path
            $stubPath = $this->getStubPath("views/{$view}.blade.stub");

            // If the stub doesn't exist, skip it
            if (!$this->files->exists($stubPath)) {
                $this->info("View stub for {$view} not found, skipping...");
                continue;
            }

            // Replace the placeholders
            $viewStub = $this->files->get($stubPath);
            $viewStub = str_replace('{{modelName}}', $name, $viewStub);
            $viewStub = str_replace('{{modelVariable}}', $modelVariable, $viewStub);

            // Replace route references to use plural form
            $viewStub = str_replace("route('{$modelVariable}.", "route('{$routeName}.", $viewStub);
            $viewStub = str_replace("route('{{modelVariable}}.", "route('{$routeName}.", $viewStub);

            // Add form fields and other dynamic content based on view type
            if ($view === 'create' || $view === 'edit') {
                // Replace form fields and select fields
                $viewStub = str_replace('{{form_fields}}', $formFields, $viewStub);
                $viewStub = str_replace('{{select_fields}}', $selectInputs, $viewStub);

            } elseif ($view === 'index') {
                // Replace table headers and cells
                $viewStub = str_replace('{{table_headers}}', $tableColumns, $viewStub);
                $viewStub = str_replace('{{table_cells}}', $tableCells, $viewStub);

            } elseif ($view === 'show') {
                // Replace detail rows
                $viewStub = str_replace('{{model_fields}}', $detailRows, $viewStub);
            }

            // Determine the view path based on module style
            if ($moduleStyle) {
                $path = base_path("Modules/{$name}/Resources/views/{$view}.blade.php");
            } else {
                $path = resource_path("views/{$modelVariable}/{$view}.blade.php");
            }

            // Create the directory if it doesn't exist
            $this->makeDirectory(dirname($path));

            // Create the file
            $this->files->put($path, $viewStub);

            $this->info("View created: {$path}");
        }
    }

    /**
     * Generate form input based on field type.
     *
     * @param string $fieldName
     * @param string $fieldType
     * @param string $modelVariable
     * @return string
     */
    protected function generateFormInput($fieldName, $fieldType, $modelVariable)
    {
        $label = ucfirst($fieldName);
        $inputType = 'text';
        $extraAttrs = '';

        // Determine input type based on field type
        switch ($fieldType) {
            case 'integer':
            case 'bigInteger':
            case 'smallInteger':
            case 'tinyInteger':
                $inputType = 'number';
                break;
            case 'decimal':
            case 'double':
            case 'float':
                $inputType = 'number';
                $extraAttrs = 'step="0.01"';
                break;
            case 'boolean':
                return $this->generateCheckboxInput($fieldName, $modelVariable);
            case 'date':
                $inputType = 'date';
                break;
            case 'time':
                $inputType = 'time';
                break;
            case 'dateTime':
            case 'timestamp':
                $inputType = 'datetime-local';
                break;
            case 'text':
                return $this->generateTextareaInput($fieldName, $modelVariable);
        }

        return <<<EOT
    <div class="form-group mb-3">
        <label for="{$fieldName}">{$label}</label>
        <input type="{$inputType}" class="form-control @error('{$fieldName}') is-invalid @enderror"
               id="{$fieldName}" name="{$fieldName}" value="{{ old('{$fieldName}', \${$modelVariable}->{$fieldName} ?? '') }}" {$extraAttrs}>
        @error('{$fieldName}')
            <span class="invalid-feedback" role="alert">
                <strong>{{ \$message }}</strong>
            </span>
        @enderror
    </div>

    EOT;
    }

    /**
     * Generate textarea input.
     *
     * @param string $fieldName
     * @param string $modelVariable
     * @return string
     */
    protected function generateTextareaInput($fieldName, $modelVariable)
    {
        $label = ucfirst($fieldName);

        return <<<EOT
    <div class="form-group mb-3">
        <label for="{$fieldName}">{$label}</label>
        <textarea class="form-control @error('{$fieldName}') is-invalid @enderror"
                  id="{$fieldName}" name="{$fieldName}" rows="4">{{ old('{$fieldName}', \${$modelVariable}->{$fieldName} ?? '') }}</textarea>
        @error('{$fieldName}')
            <span class="invalid-feedback" role="alert">
                <strong>{{ \$message }}</strong>
            </span>
        @enderror
    </div>

    EOT;
    }

    /**
     * Generate checkbox input.
     *
     * @param string $fieldName
     * @param string $modelVariable
     * @return string
     */
    protected function generateCheckboxInput($fieldName, $modelVariable)
    {
        $label = ucfirst($fieldName);

        return <<<EOT
    <div class="form-check mb-3">
        <input type="checkbox" class="form-check-input @error('{$fieldName}') is-invalid @enderror"
               id="{$fieldName}" name="{$fieldName}" value="1"
               {{ old('{$fieldName}', \${$modelVariable}->{$fieldName} ?? '') ? 'checked' : '' }}>
        <label class="form-check-label" for="{$fieldName}">{$label}</label>
        @error('{$fieldName}')
            <span class="invalid-feedback" role="alert">
                <strong>{{ \$message }}</strong>
            </span>
        @enderror
    </div>

    EOT;
    }

    /**
     * Generate select input.
     *
     * @param string $fieldName
     * @param array $options
     * @param string $modelVariable
     * @return string
     */
    protected function generateSelectInput($fieldName, array $options, $modelVariable)
    {
        $label = ucfirst($fieldName);

        $optionsHtml = '';
        foreach ($options as $option) {
            $option = trim($option);
            $optionsHtml .= "        <option value=\"{$option}\" {{ old('{$fieldName}', \${$modelVariable}->{$fieldName} ?? '') == '{$option}' ? 'selected' : '' }}>{$option}</option>\n";
        }

        return <<<EOT
    <div class="form-group mb-3">
        <label for="{$fieldName}">{$label}</label>
        <select class="form-control @error('{$fieldName}') is-invalid @enderror" id="{$fieldName}" name="{$fieldName}">
            <option value="">Select {$label}</option>
    {$optionsHtml}
        </select>
        @error('{$fieldName}')
            <span class="invalid-feedback" role="alert">
                <strong>{{ \$message }}</strong>
            </span>
        @enderror
    </div>

    EOT;
    }

    /**
     * Generate detail row for show view.
     *
     * @param string $fieldName
     * @param string $modelVariable
     * @return string
     */
    protected function generateDetailRow($fieldName, $modelVariable)
    {
        $label = ucfirst($fieldName);

        return <<<EOT
    <tr>
        <th>{$label}</th>
        <td>{{ \${$modelVariable}->{$fieldName} }}</td>
    </tr>

    EOT;
    }

    /**
     * Add API resource routes to routes file.
     *
     * @param string $name
     * @param bool $moduleStyle
     * @return void
     */
    protected function addApiRoutes($name, $moduleStyle = false)
    {
        $this->info("Adding API routes for {$name}...");

        $modelVariable = lcfirst($name);

        if ($moduleStyle) {
            // For module style, create a dedicated API routes file
            $routeFile = base_path("Modules/{$name}/api-routes.php");

            // Create basic routes file content
            $routeContent = "<?php\n\n";
            $routeContent .= "use Illuminate\\Support\\Facades\\Route;\n";
            $routeContent .= "use Modules\\{$name}\\Http\\Controllers\\{$name}Controller;\n\n";

            // Use API version from config if set
            $apiVersion = config('module-generator.api_version');
            $versionPrefix = $apiVersion ? "'{$apiVersion}/" : "'";

            $routeContent .= "Route::prefix('api')->group(function () {\n";
            $routeContent .= "    Route::apiResource({$versionPrefix}{$modelVariable}s', {$name}Controller::class);\n";
            $routeContent .= "});\n";

            // Create the file
            $this->files->put($routeFile, $routeContent);

            // Create or update service provider to load these routes
            $this->createModuleServiceProvider($name, true);

            $this->info("API routes added to {$routeFile}");

        } else {
            // Regular Laravel app routes
            $routeFile = base_path('routes/api.php');

            // Check if route exists to avoid duplicates
            $routeContents = $this->files->get($routeFile);

            if (str_contains($routeContents, "{$name}Controller")) {
                $this->info("API routes for {$name} already exist. Skipping...");
                return;
            }

            // Use API version from config if set
            $apiVersion = config('module-generator.api_version');
            $versionPrefix = $apiVersion ? "'{$apiVersion}/" : "'";

            // Add route at the end of the file
            $route = "\n// {$name} API routes\n" .
                     "Route::apiResource({$versionPrefix}{$modelVariable}s', App\\Http\\Controllers\\{$name}Controller::class);\n";

            $this->files->append($routeFile, $route);

            $this->info("API routes added to {$routeFile}");
        }
    }



    /**
     * Generate HTML for a select dropdown.
     *
     * @param string $field
     * @param array $options
     * @param bool $isEdit
     * @return string
     */
    protected function generateSelectDropdownHtml($field, array $options, $isEdit = false)
    {
        $html = "<div class=\"form-group\">\n";
        $html .= "    <label for=\"{$field}\">" . ucfirst($field) . "</label>\n";
        $html .= "    <select class=\"form-control\" id=\"{$field}\" name=\"{$field}\">\n";

        foreach ($options as $option) {
            $selected = $isEdit ? "{{ \${{modelVariable}}->{$field} == '{$option}' ? 'selected' : '' }}" : "";
            $html .= "        <option value=\"{$option}\" {$selected}>{$option}</option>\n";
        }

        $html .= "    </select>\n";
        $html .= "</div>";

        return $html;
    }

    /**
     * Add resource routes to routes file.
     *
     * @param string $name
     * @param bool $moduleStyle
     * @return void
     */
    protected function addRoutes($name, $moduleStyle = false)
    {
        $this->info("Adding routes for {$name}...");

        $modelVariable = lcfirst($name);

        if ($moduleStyle) {
            // For module style, create a dedicated routes file
            $routeFile = base_path("Modules/{$name}/routes.php");

            // Create basic routes file content
            $routeContent = "<?php\n\n";
            $routeContent .= "use Illuminate\\Support\\Facades\\Route;\n";
            $routeContent .= "use Modules\\{$name}\\Http\\Controllers\\{$name}Controller;\n\n";
            $routeContent .= "Route::resource('{$modelVariable}s', {$name}Controller::class);\n";

            // Create the file
            $this->files->put($routeFile, $routeContent);

            // Create a service provider to load these routes if it doesn't exist
            $this->createModuleServiceProvider($name);

            $this->info("Routes added to {$routeFile}");

        } else {
            // Regular Laravel app routes
            $routeFile = base_path('routes/web.php');

            // Check if route exists to avoid duplicates
            $routeContents = $this->files->get($routeFile);

            if (str_contains($routeContents, "{$name}Controller")) {
                $this->info("Routes for {$name} already exist. Skipping...");
                return;
            }

            // Add route at the end of the file
            $route = "\n// {$name} routes\nRoute::resource('{$modelVariable}s', App\\Http\\Controllers\\{$name}Controller::class);\n";

            $this->files->append($routeFile, $route);

            $this->info("Routes added to {$routeFile}");
        }
    }

    /**
     * Create a model factory for the module.
     *
     * @param string $name
     * @param string|null $fields
     * @param array $selectFields
     * @param bool $moduleStyle
     * @return void
     */
    protected function createFactory($name, $fields = null, array $selectFields = [], $moduleStyle = false)
    {
        $this->info("Creating Factory: {$name}Factory");

        // Get the stub path
        $stubPath = $this->getStubPath('factory.stub');

        // Replace the placeholders
        $factoryStub = $this->files->get($stubPath);
        $factoryStub = str_replace('{{modelName}}', $name, $factoryStub);

        // Adjust the namespace and imports if using module style
        if ($moduleStyle) {
            $factoryStub = str_replace(
                'namespace Database\Factories;',
                "namespace Modules\\{$name}\\Database\\Factories;",
                $factoryStub
            );

            // Update model import
            $factoryStub = str_replace(
                "use App\Models\\{$name};",
                "use Modules\\{$name}\\Models\\{$name};",
                $factoryStub
            );
        }

        // Generate factory fields
        $factoryFields = '';
        if (!empty($fields)) {
            $fieldArray = explode(',', $fields);
            $factoryFieldsArray = [];

            foreach ($fieldArray as $field) {
                $field = trim($field);
                $parts = explode(':', $field);

                $fieldName = trim($parts[0]);
                $fieldType = isset($parts[1]) ? trim($parts[1]) : 'string';

                // Skip if this is a select field (handled separately)
                if (array_key_exists($fieldName, $selectFields)) {
                    continue;
                }

                $faker = $this->getFactoryFaker($fieldType);
                $factoryFieldsArray[] = "'{$fieldName}' => {$faker}";
            }

            // Add select fields
            foreach ($selectFields as $field => $options) {
                $randomOption = '$this->faker->randomElement([' .
                    implode(', ', array_map(fn ($opt) => "'" . trim($opt) . "'", $options)) . '])';
                $factoryFieldsArray[] = "'{$field}' => {$randomOption}";
            }

            $factoryFields = implode(",\n            ", $factoryFieldsArray);
        }

        $factoryStub = str_replace('{{factoryFields}}', $factoryFields, $factoryStub);

        // Determine the factory path based on module style
        if ($moduleStyle) {
            $path = base_path("Modules/{$name}/Database/Factories/{$name}Factory.php");
        } else {
            $path = database_path("factories/{$name}Factory.php");
        }

        // Create the directory if it doesn't exist
        $this->makeDirectory(dirname($path));

        // Create the file
        $this->files->put($path, $factoryStub);

        $this->info("Factory created: {$path}");
    }

    /**
     * Get the appropriate Faker method for the field type.
     *
     * @param string $fieldType
     * @return string
     */
    protected function getFactoryFaker($fieldType)
    {
        switch ($fieldType) {
            case 'string':
                return '$this->faker->sentence(3)';
            case 'text':
            case 'longText':
                return '$this->faker->paragraphs(3, true)';
            case 'integer':
            case 'bigInteger':
            case 'smallInteger':
            case 'tinyInteger':
                return '$this->faker->numberBetween(1, 1000)';
            case 'decimal':
            case 'double':
            case 'float':
                return '$this->faker->randomFloat(2, 1, 1000)';
            case 'boolean':
                return '$this->faker->boolean';
            case 'date':
                return '$this->faker->date()';
            case 'dateTime':
            case 'timestamp':
                return '$this->faker->dateTime()';
            case 'time':
                return '$this->faker->time()';
            case 'year':
                return '$this->faker->year()';
            case 'email':
                return '$this->faker->safeEmail()';
            case 'url':
                return '$this->faker->url()';
            case 'password':
                return 'bcrypt($this->faker->password())';
            case 'uuid':
                return '$this->faker->uuid()';
            case 'ipAddress':
                return '$this->faker->ipv4()';
            case 'macAddress':
                return '$this->faker->macAddress()';
            default:
                return '$this->faker->word()';
        }
    }

    /**
     * Create a service provider for the module.
     *
     * @param string $name
     * @param bool $includeApiRoutes
     * @return void
     */
    protected function createModuleServiceProvider($name, $includeApiRoutes = false)
    {
        $providerPath = base_path("Modules/{$name}/{$name}ServiceProvider.php");

        // If the provider already exists and we need to add API routes
        if ($this->files->exists($providerPath) && $includeApiRoutes) {
            $providerContent = $this->files->get($providerPath);

            // Check if api-routes is already included
            if (!str_contains($providerContent, 'api-routes.php')) {
                $pattern = '/\$this->loadRoutesFrom\(__DIR__ \. \'\/routes\.php\'\);/';
                $replacement = "\$this->loadRoutesFrom(__DIR__ . '/routes.php');\n        \$this->loadRoutesFrom(__DIR__ . '/api-routes.php');";
                $providerContent = preg_replace($pattern, $replacement, $providerContent);

                $this->files->put($providerPath, $providerContent);
                $this->info("Service provider updated to include API routes");
            }

            return;
        }

        // Create a new service provider
        $stubPath = $this->getStubPath('module-service-provider.stub');

        if (!$this->files->exists($stubPath)) {
            // Create a basic service provider if stub not found
            $providerContent = "<?php\n\n";
            $providerContent .= "namespace Modules\\{$name};\n\n";
            $providerContent .= "use Illuminate\\Support\\ServiceProvider;\n\n";
            $providerContent .= "class {$name}ServiceProvider extends ServiceProvider\n";
            $providerContent .= "{\n";
            $providerContent .= "    /**\n";
            $providerContent .= "     * Register services.\n";
            $providerContent .= "     */\n";
            $providerContent .= "    public function register(): void\n";
            $providerContent .= "    {\n";
            $providerContent .= "        //\n";
            $providerContent .= "    }\n\n";
            $providerContent .= "    /**\n";
            $providerContent .= "     * Bootstrap services.\n";
            $providerContent .= "     */\n";
            $providerContent .= "    public function boot(): void\n";
            $providerContent .= "    {\n";
            $providerContent .= "        \$this->loadRoutesFrom(__DIR__ . '/routes.php');\n";

            if ($includeApiRoutes) {
                $providerContent .= "        \$this->loadRoutesFrom(__DIR__ . '/api-routes.php');\n";
            }

            $providerContent .= "        \$this->loadViewsFrom(__DIR__ . '/Resources/views', '{$name}');\n";
            $providerContent .= "        \$this->loadMigrationsFrom(__DIR__ . '/Database/Migrations');\n";
            $providerContent .= "    }\n";
            $providerContent .= "}\n";
        } else {
            // Use the stub
            $providerContent = $this->files->get($stubPath);
            $providerContent = str_replace('{{moduleName}}', $name, $providerContent);

            // Add API routes if needed
            if ($includeApiRoutes && !str_contains($providerContent, 'api-routes.php')) {
                $pattern = '/\$this->loadRoutesFrom\(__DIR__ \. \'\/routes\.php\'\);/';
                $replacement = "\$this->loadRoutesFrom(__DIR__ . '/routes.php');\n        \$this->loadRoutesFrom(__DIR__ . '/api-routes.php');";
                $providerContent = preg_replace($pattern, $replacement, $providerContent);
            }
        }

        $this->files->put($providerPath, $providerContent);

        $this->info("Module service provider created: {$providerPath}");
    }



}
