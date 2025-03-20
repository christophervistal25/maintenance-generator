<?php

namespace DevKits\ModuleGenerator\Tests;

use Orchestra\Testbench\TestCase;
use DevKits\ModuleGenerator\ServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class MakeModuleCommandTest extends TestCase
{
    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create application directories if they don't exist
        if (!File::exists(app_path('Models'))) {
            File::makeDirectory(app_path('Models'), 0755, true);
        }

        if (!File::exists(app_path('Http/Controllers'))) {
            File::makeDirectory(app_path('Http/Controllers'), 0755, true);
        }

        if (!File::exists(database_path('migrations'))) {
            File::makeDirectory(database_path('migrations'), 0755, true);
        }
    }

    /**
     * Clean up the testing environment before the next test.
     */
    protected function tearDown(): void
    {
        // Clean up the test files
        if (File::exists(app_path('Models/Product.php'))) {
            File::delete(app_path('Models/Product.php'));
        }

        if (File::exists(app_path('Http/Controllers/ProductController.php'))) {
            File::delete(app_path('Http/Controllers/ProductController.php'));
        }

        // Delete all migrations that contain 'create_products_table'
        $migrations = File::glob(database_path('migrations/*_create_products_table.php'));
        foreach ($migrations as $migration) {
            File::delete($migration);
        }

        parent::tearDown();
    }

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
        ];
    }

    /**
     * Test the make:module command.
     */
    public function testMakeModule()
    {
        // Run the command
        Artisan::call('make:module', ['name' => 'Product']);

        // Check if the files were created
        $this->assertTrue(File::exists(app_path('Models/Product.php')));
        $this->assertTrue(File::exists(app_path('Http/Controllers/ProductController.php')));

        // Check for migration file
        $migrationFile = File::glob(database_path('migrations/*_create_products_table.php'));
        $this->assertNotEmpty($migrationFile);
    }

    /**
     * Test the make:module command with fields.
     */
    public function testMakeModuleWithFields()
    {
        // Run the command with fields
        Artisan::call('make:module', [
            'name' => 'Product',
            '--fields' => 'name:string,price:decimal,description:text'
        ]);

        // Check if the files were created
        $this->assertTrue(File::exists(app_path('Models/Product.php')));
        $this->assertTrue(File::exists(app_path('Http/Controllers/ProductController.php')));

        // Check for migration file
        $migrationFile = File::glob(database_path('migrations/*_create_products_table.php'));
        $this->assertNotEmpty($migrationFile);

        // Check if the model contains the fillable fields
        $modelContent = File::get(app_path('Models/Product.php'));
        $this->assertStringContainsString("'name', 'price', 'description'", $modelContent);

        // Check if the migration contains the fields
        $migrationContent = File::get($migrationFile[0]);
        $this->assertStringContainsString("\$table->string('name');", $migrationContent);
        $this->assertStringContainsString("\$table->decimal('price');", $migrationContent);
        $this->assertStringContainsString("\$table->text('description');", $migrationContent);
    }

    /**
     * Test the make:module command with select fields.
     */
    public function testMakeModuleWithSelectFields()
    {
        // Run the command with fields and selects
        Artisan::call('make:module', [
            'name' => 'Product',
            '--fields' => 'name:string,price:decimal',
            '--selects' => 'status:active,inactive,archived;category:electronics,clothing,books'
        ]);

        // Check if the files were created
        $this->assertTrue(File::exists(app_path('Models/Product.php')));
        $this->assertTrue(File::exists(app_path('Http/Controllers/ProductController.php')));

        // Check for migration file
        $migrationFile = File::glob(database_path('migrations/*_create_products_table.php'));
        $this->assertNotEmpty($migrationFile);

        // Check if the model contains the constants for select options
        $modelContent = File::get(app_path('Models/Product.php'));
        $this->assertStringContainsString("STATUS_OPTIONS", $modelContent);
        $this->assertStringContainsString("CATEGORY_OPTIONS", $modelContent);
        $this->assertStringContainsString("'active', 'inactive', 'archived'", $modelContent);
        $this->assertStringContainsString("'electronics', 'clothing', 'books'", $modelContent);

        // Check if the migration contains the enum fields
        $migrationContent = File::get($migrationFile[0]);
        $this->assertStringContainsString("\$table->enum('status',", $migrationContent);
        $this->assertStringContainsString("\$table->enum('category',", $migrationContent);

        // Check if the controller contains validation rules for select fields
        $controllerContent = File::get(app_path('Http/Controllers/ProductController.php'));
        $this->assertStringContainsString("'status' => 'required|in:'", $controllerContent);
        $this->assertStringContainsString("'category' => 'required|in:'", $controllerContent);
        $this->assertStringContainsString("\$statusOptions", $controllerContent);
        $this->assertStringContainsString("\$categoryOptions", $controllerContent);
    }
}
