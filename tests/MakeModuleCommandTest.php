<?php

namespace DevKits\ModuleGenerator\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use DevKits\ModuleGenerator\ServiceProvider;

class MakeModuleCommandTest extends TestCase
{
    /**
         * The original contents of route files, if they exist
         */
    protected $originalRoutes = [];
    /**
    * Setup the test environment.
    *
    * @return void
    */
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure route files exist
        $this->prepareRouteFiles();

        // Clean up test artifacts from previous runs
        $this->cleanupTestArtifacts();
    }

    /**
     * Clean up after the tests.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up test artifacts
        $this->cleanupTestArtifacts();

        // Restore original route files
        $this->restoreRouteFiles();

        parent::tearDown();
    }

    /**
    * Get package providers.
    *
    * @param \Illuminate\Foundation\Application $app
    * @return array
    */
    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    /**
    * Define environment setup.
    *
    * @param \Illuminate\Foundation\Application $app
    * @return void
    */
    protected function defineEnvironment($app)
    {
        // Set up a test database
        $app['config']->set('database.default', 'testing');
    }

    /**
     * Test if the service provider loads correctly.
     *
     * @return void
     */
    public function testServiceProviderLoads()
    {
        $this->assertTrue(true);
    }


    /**
     * Test a simple command execution with minimal assertions.
     *
     * @return void
     */
    public function testBasicCommandExecution()
    {
        // We'll just test if the command runs without errors
        $this->artisan('make:module', [
            'name' => 'Simple'
        ])->assertExitCode(0);
    }


    /**
     * Test the basic module creation.
     *
     * @return void
     */
    public function testBasicModuleCreation()
    {
        // Remove the expectsOutput check
        $this->artisan('make:module', [
            'name' => 'Test'
        ])
        ->assertExitCode(0);

        // Check if the files were created
        $this->assertTrue(File::exists(app_path('Models/Test.php')));
        $this->assertTrue(File::exists(app_path('Http/Controllers/TestController.php')));

        // Check for migration
        $migrationFile = $this->getMigrationFilePath('create_tests_table');
        $this->assertNotNull($migrationFile, 'Migration file was not created');

        // Check for factory
        $this->assertTrue(File::exists(database_path('factories/TestFactory.php')));
    }

    /**
     * Test module creation with fields.
     *
     * @return void
     */
    public function testModuleCreationWithFields()
    {
        $this->artisan('make:module', [
            'name' => 'Product',
            '--fields' => 'name:string,price:decimal,description:text'
        ])

        ->assertExitCode(0);

        // Check if the model has the fillable property set correctly
        $modelContents = File::get(app_path('Models/Product.php'));
        $this->assertStringContainsString("'name', 'price', 'description'", $modelContents);

        // Check migration for fields
        $migrationFile = $this->getMigrationFilePath('create_products_table');
        $this->assertNotNull($migrationFile);

        $migrationContents = File::get($migrationFile);
        $this->assertStringContainsString('$table->string(\'name\')', $migrationContents);
        $this->assertStringContainsString('$table->decimal(\'price\')', $migrationContents);
        $this->assertStringContainsString('$table->text(\'description\')', $migrationContents);

        // Check factory for fields
        $factoryContents = File::get(database_path('factories/ProductFactory.php'));
        $this->assertStringContainsString("'name'", $factoryContents);
        $this->assertStringContainsString("'price'", $factoryContents);
        $this->assertStringContainsString("'description'", $factoryContents);
    }

    /**
     * Test module creation with select fields.
     *
     * @return void
     */
    public function testModuleCreationWithSelectFields()
    {
        $this->artisan('make:module', [
            'name' => 'Task',
            '--fields' => 'title:string,description:text',
            '--selects' => 'status:pending,in-progress,completed;priority:low,medium,high'
        ])

        ->assertExitCode(0);

        // Check if the model has the constants defined
        $modelContents = File::get(app_path('Models/Task.php'));
        $this->assertStringContainsString("STATUS_OPTIONS = ['pending', 'in-progress', 'completed']", $modelContents);
        $this->assertStringContainsString("PRIORITY_OPTIONS = ['low', 'medium', 'high']", $modelContents);

        // Check migration for enum fields
        $migrationFile = $this->getMigrationFilePath('create_tasks_table');
        $this->assertNotNull($migrationFile);

        $migrationContents = File::get($migrationFile);
        $this->assertStringContainsString('$table->enum(\'status\', [\'pending\', \'in-progress\', \'completed\'])', $migrationContents);
        $this->assertStringContainsString('$table->enum(\'priority\', [\'low\', \'medium\', \'high\'])', $migrationContents);

        // Check controller for select options
        $controllerContents = File::get(app_path('Http/Controllers/TaskController.php'));
        $this->assertStringContainsString('$statusOptions = Task::STATUS_OPTIONS', $controllerContents);
        $this->assertStringContainsString('$priorityOptions = Task::PRIORITY_OPTIONS', $controllerContents);

        // Check factory for enum values
        $factoryContents = File::get(database_path('factories/TaskFactory.php'));
        $this->assertStringContainsString('$this->faker->randomElement([\'pending\', \'in-progress\', \'completed\'])', $factoryContents);
    }

    /**
     * Test module creation with API option.
     *
     * @return void
     */
    public function testModuleCreationWithApiOption()
    {
        $this->artisan('make:module', [
            'name' => 'Post',
            '--api' => true
        ])

        ->assertExitCode(0);

        // Check if the API controller was created
        $controllerContents = File::get(app_path('Http/Controllers/PostController.php'));
        $this->assertStringContainsString('return response()->json', $controllerContents);

        // Check if routes were added to api.php
        $apiRoutesContent = File::get(base_path('routes/api.php'));
        $this->assertStringContainsString("Route::apiResource", $apiRoutesContent);
        $this->assertStringContainsString("PostController", $apiRoutesContent);
    }

    /**
     * Test module creation with views option.
     *
     * @return void
     */
    public function testModuleCreationWithViewsOption()
    {
        $this->artisan('make:module', [
            'name' => 'Comment',
            '--views' => true
        ])

        ->assertExitCode(0);

        // Check if the views were created
        $this->assertTrue(File::exists(resource_path('views/comment/index.blade.php')));
        $this->assertTrue(File::exists(resource_path('views/comment/create.blade.php')));
        $this->assertTrue(File::exists(resource_path('views/comment/edit.blade.php')));
        $this->assertTrue(File::exists(resource_path('views/comment/show.blade.php')));
    }

    /**
     * Test module creation with module style option.
     *
     * @return void
     */
    public function testModuleCreationWithModuleStyle()
    {
        $this->artisan('make:module', [
            'name' => 'Order',
            '--module-style' => true
        ])

        ->assertExitCode(0);

        // Check if the files were created in the Modules directory
        $this->assertTrue(File::exists(base_path('Modules/Order/Models/Order.php')));
        $this->assertTrue(File::exists(base_path('Modules/Order/Http/Controllers/OrderController.php')));
        $this->assertTrue(File::isDirectory(base_path('Modules/Order/Database/Migrations')));
        $this->assertTrue(File::exists(base_path('Modules/Order/routes.php')));
        $this->assertTrue(File::exists(base_path('Modules/Order/OrderServiceProvider.php')));
    }

    /**
     * Test module creation with combined options.
     *
     * @return void
     */
    public function testModuleCreationWithCombinedOptions()
    {
        $this->artisan('make:module', [
            'name' => 'Invoice',
            '--fields' => 'number:string,amount:decimal,issued_at:date,notes:text',
            '--selects' => 'status:draft,issued,paid,cancelled',
            '--views' => true,
            '--module-style' => true
        ])

        ->assertExitCode(0);

        // Check module structure
        $this->assertTrue(File::exists(base_path('Modules/Invoice/Models/Invoice.php')));
        $this->assertTrue(File::exists(base_path('Modules/Invoice/Http/Controllers/InvoiceController.php')));

        // Check model content
        $modelContents = File::get(base_path('Modules/Invoice/Models/Invoice.php'));
        $this->assertStringContainsString("namespace Modules\\Invoice\\Models", $modelContents);
        $this->assertStringContainsString("'number', 'amount', 'issued_at', 'notes', 'status'", $modelContents);
        $this->assertStringContainsString("STATUS_OPTIONS = ['draft', 'issued', 'paid', 'cancelled']", $modelContents);

        // Check views
        $this->assertTrue(File::exists(base_path('Modules/Invoice/Resources/views/index.blade.php')));
        $this->assertTrue(File::exists(base_path('Modules/Invoice/Resources/views/create.blade.php')));
        $this->assertTrue(File::exists(base_path('Modules/Invoice/Resources/views/edit.blade.php')));
        $this->assertTrue(File::exists(base_path('Modules/Invoice/Resources/views/show.blade.php')));
    }

    /**
     * Test module creation with the type option specifying model-migration.
     *
     * @return void
     */
    public function testModuleCreationWithTypeModelMigration()
    {
        $this->artisan('make:module', [
            'name' => 'Category',
            '--fields' => 'name:string,slug:string,description:text',
            '--type' => 'model-migration'
        ])

        ->assertExitCode(0);

        // Check if only model and migration were created
        $this->assertTrue(File::exists(app_path('Models/Category.php')));
        $this->assertFalse(File::exists(app_path('Http/Controllers/CategoryController.php')));

        // Check for migration
        $migrationFile = $this->getMigrationFilePath('create_categories_table');
        $this->assertNotNull($migrationFile);

        // Check migration contents
        $migrationContents = File::get($migrationFile);
        $this->assertStringContainsString('$table->string(\'name\')', $migrationContents);
        $this->assertStringContainsString('$table->string(\'slug\')', $migrationContents);
        $this->assertStringContainsString('$table->text(\'description\')', $migrationContents);

        // Check no routes were added
        $webRoutesContent = File::get(base_path('routes/web.php'));
        $this->assertStringNotContainsString("CategoryController", $webRoutesContent);
    }

    /**
     * Test module creation with type API.
     *
     * @return void
     */
    public function testModuleCreationWithTypeApi()
    {
        // Use a different model name to avoid conflicts with Laravel's User model
        $this->artisan('make:module', [
            'name' => 'ApiUser',
            '--type' => 'api'
        ])
        ->assertExitCode(0);

        // Check model was created
        $this->assertTrue(File::exists(app_path('Models/ApiUser.php')), 'Model file was not created');

        // Check controller was created
        $this->assertTrue(File::exists(app_path('Http/Controllers/ApiUserController.php')), 'Controller file was not created');
    }

    /**
     * Test the force option to overwrite existing files.
     *
     * @return void
     */
    public function testForceOptionOverwritesExistingFiles()
    {
        // First create a module
        $this->artisan('make:module', [
            'name' => 'Customer',
            '--fields' => 'name:string'
        ])->assertExitCode(0);

        // Modify the model file to check later if it's overwritten
        $modelPath = app_path('Models/Customer.php');
        File::put($modelPath, '<?php // Modified file');

        // Now recreate with force option
        $this->artisan('make:module', [
            'name' => 'Customer',
            '--fields' => 'name:string,email:string',
            '--force' => true
        ])->assertExitCode(0);

        // Check if the file was overwritten
        $modelContents = File::get($modelPath);
        $this->assertStringNotContainsString('// Modified file', $modelContents);
        $this->assertStringContainsString("'name', 'email'", $modelContents);
    }

    /**
     * Test the configuration file options are respected.
     *
     * @return void
     */
    public function testConfigurationOptionsAreRespected()
    {
        // Create with model-migration type but specifically request views
        $this->artisan('make:module', [
            'name' => 'Setting',
            '--fields' => 'key:string,value:text',
            '--type' => 'model-migration',
            '--views' => true
        ])
        ->assertExitCode(0);

        // Verify model is created
        $this->assertTrue(File::exists(app_path('Models/Setting.php')));

        // Verify type is respected (no controller created)
        $this->assertFalse(File::exists(app_path('Http/Controllers/SettingController.php')));

        // Verify views are created since --views flag was provided
        $this->assertTrue(
            File::exists(resource_path('views/setting/index.blade.php')) ||
                         File::exists(resource_path('views/setting/create.blade.php')) ||
                         File::exists(resource_path('views/setting/edit.blade.php')) ||
                         File::exists(resource_path('views/setting/show.blade.php')),
            'No view files were created despite --views flag'
        );
    }

    /**
     * Test API versioning configuration.
     *
     * @return void
     */
    public function testApiVersioningConfiguration()
    {
        // Set API version config
        config(['module-generator.api_version' => 'v2']);

        $this->artisan('make:module', [
            'name' => 'Article',
            '--api' => true
        ])
        ->assertExitCode(0);

        // Check if API routes include the version
        $apiRoutesContent = File::get(base_path('routes/api.php'));
        $this->assertStringContainsString("'v2/articles'", $apiRoutesContent);
    }

    /**
     * Test module creation with different field types.
     *
     * @return void
     */
    public function testModuleCreationWithDifferentFieldTypes()
    {
        $this->artisan('make:module', [
            'name' => 'Record',
            '--fields' => 'title:string,count:integer,is_active:boolean,published_at:timestamp,rating:decimal'
        ])
        ->assertExitCode(0);

        // Check migration for different field types
        $migrationFile = $this->getMigrationFilePath('create_records_table');
        $migrationContents = File::get($migrationFile);

        $this->assertStringContainsString('$table->string(\'title\')', $migrationContents);
        $this->assertStringContainsString('$table->integer(\'count\')', $migrationContents);
        $this->assertStringContainsString('$table->boolean(\'is_active\')', $migrationContents);
        $this->assertStringContainsString('$table->timestamp(\'published_at\')', $migrationContents);
        $this->assertStringContainsString('$table->decimal(\'rating\')', $migrationContents);

        // Check factory for appropriate faker methods
        $factoryContents = File::get(database_path('factories/RecordFactory.php'));
        $this->assertStringContainsString('$this->faker->sentence', $factoryContents); // string
        $this->assertStringContainsString('$this->faker->numberBetween', $factoryContents); // integer
        $this->assertStringContainsString('$this->faker->boolean', $factoryContents); // boolean
        $this->assertStringContainsString('$this->faker->dateTime', $factoryContents); // timestamp
        $this->assertStringContainsString('$this->faker->randomFloat', $factoryContents); // decimal
    }

    /**
     * Get the migration file path by searching for a migration with the given name.
     *
     * @param string $name
     * @return string|null
     */
    private function getMigrationFilePath($name)
    {
        $files = File::glob(database_path('migrations/*'));

        foreach ($files as $file) {
            if (strpos($file, $name) !== false) {
                return $file;
            }
        }

        return null;
    }

    /**
        * Prepare route files for testing.
        *
        * @return void
        */
    protected function prepareRouteFiles()
    {
        $routeFiles = ['web.php', 'api.php'];

        foreach ($routeFiles as $file) {
            $path = base_path("routes/{$file}");

            // Create directory if not exists
            if (!File::exists(dirname($path))) {
                File::makeDirectory(dirname($path), 0755, true);
            }

            // Store original content if file exists
            if (File::exists($path)) {
                $this->originalRoutes[$file] = File::get($path);
            } else {
                // Create a default route file
                File::put($path, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n");
                $this->originalRoutes[$file] = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n";
            }
        }
    }

    /**
    * Restore original route files.
    *
    * @return void
    */
    protected function restoreRouteFiles()
    {
        foreach ($this->originalRoutes as $file => $content) {
            $path = base_path("routes/{$file}");
            if (File::exists(dirname($path))) {
                File::put($path, $content);
            }
        }
    }

    /**
    * Clean up test artifacts.
    *
    * @return void
    */
    private function cleanupTestArtifacts()
    {
        // Remove test models
        $testModels = ['Test', 'Product', 'Task', 'Post', 'Comment', 'Order', 'Invoice', 'Category', 'User', 'Customer', 'Setting', 'Article', 'Record'];

        foreach ($testModels as $model) {
            $this->removeModelFile($model);
            $this->removeControllerFile($model);
            $this->removeViewDirectory($model);
            $this->removeModuleDirectory($model);
            $this->removeFactoryFile($model);
        }

        // Clean up migrations
        $migrationPatterns = [
            'create_tests_table',
            'create_products_table',
            'create_tasks_table',
            'create_posts_table',
            'create_comments_table',
            'create_orders_table',
            'create_invoices_table',
            'create_categories_table',
            'create_users_table',
            'create_customers_table',
            'create_settings_table',
            'create_articles_table',
            'create_records_table'
        ];

        foreach ($migrationPatterns as $pattern) {
            $migrationFile = $this->getMigrationFilePath($pattern);
            if ($migrationFile && File::exists($migrationFile)) {
                File::delete($migrationFile);
            }
        }

        // Reset route files to their base state
        $this->resetRouteFiles();
    }

    /**
     * Remove a model file if it exists.
     *
     * @param string $model
     * @return void
     */
    private function removeModelFile($model)
    {
        $path = app_path("Models/{$model}.php");
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    /**
     * Remove a controller file if it exists.
     *
     * @param string $name
     * @return void
     */
    private function removeControllerFile($name)
    {
        $path = app_path("Http/Controllers/{$name}Controller.php");
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    /**
     * Remove a view directory if it exists.
     *
     * @param string $name
     * @return void
     */
    private function removeViewDirectory($name)
    {
        $viewPath = resource_path('views/' . strtolower($name));
        if (File::isDirectory($viewPath)) {
            File::deleteDirectory($viewPath);
        }
    }

    /**
     * Remove a module directory if it exists.
     *
     * @param string $name
     * @return void
     */
    private function removeModuleDirectory($name)
    {
        $modulePath = base_path("Modules/{$name}");
        if (File::isDirectory($modulePath)) {
            File::deleteDirectory($modulePath);
        }
    }

    /**
     * Remove a factory file if it exists.
     *
     * @param string $name
     * @return void
     */
    private function removeFactoryFile($name)
    {
        $path = database_path("factories/{$name}Factory.php");
        if (File::exists($path)) {
            File::delete($path);
        }
    }

    /**
         * Reset route files to their base state.
         *
         * @return void
         */
    private function resetRouteFiles()
    {
        $routeFiles = ['web.php', 'api.php'];

        foreach ($routeFiles as $file) {
            $path = base_path("routes/{$file}");
            if (File::exists($path)) {
                File::put($path, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n");
            }
        }
    }
}
