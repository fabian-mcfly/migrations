<?php
declare(strict_types=1);

namespace Migrations\Test\TestCase\Migration;

use Cake\Datasource\ConnectionManager;
use DateTime;
use InvalidArgumentException;
use Migrations\Config\Config;
use Migrations\Db\Adapter\AdapterInterface;
use Migrations\Migration\Environment;
use Migrations\Migration\Manager;
use Phinx\Console\Command\AbstractCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;

class ManagerTest extends TestCase
{
    /**
     * @var \Phinx\Config\Config
     */
    protected $config;

    /**
     * @var \Symfony\Component\Console\Input\InputInterface $input
     */
    protected $input;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected $output;

    /**
     * @var Manager
     */
    private $manager;

    protected function setUp(): void
    {
        $this->config = new Config($this->getConfigArray());
        $this->input = new ArrayInput([]);
        $this->output = new StreamOutput(fopen('php://memory', 'a', false));
        $this->output->setDecorated(false);
        $this->manager = new Manager($this->config, $this->input, $this->output);
    }

    protected static function getDriverType(): string
    {
        $config = ConnectionManager::getConfig('test');
        if (!$config) {
            throw new RuntimeException('Cannot read configuration for test connection');
        }

        return $config['scheme'];
    }

    protected function tearDown(): void
    {
        $this->manager = null;
    }

    private static function getCorrectedPath($path)
    {
        return str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Returns a sample configuration array for use with the unit tests.
     *
     * @return array
     */
    public static function getConfigArray()
    {
        $config = [];
        if (static::getDriverType() === 'mysql') {
            $dbConfig = ConnectionManager::getConfig('test');
            $config = [
                'adapter' => $dbConfig['scheme'],
                'user' => $dbConfig['username'],
                'pass' => $dbConfig['password'],
                'host' => $dbConfig['host'],
                'name' => $dbConfig['database'],
            ];
        }

        return [
            'paths' => [
                'migrations' => ROOT . '/config/ManagerMigrations',
                'seeds' => ROOT . '/config/ManagerSeeds',
            ],
            'environments' => [
                'default_migration_table' => 'phinxlog',
                'default_environment' => 'production',
                'production' => $config,
            ],
            'data_domain' => [
                'phone_number' => [
                    'type' => 'string',
                    'null' => true,
                    'length' => 15,
                ],
            ],
        ];
    }

    protected function getConfigWithPlugin($paths = [])
    {
        $paths = [
            'migrations' => ROOT . 'Plugin/Manager/config/Migrations',
            'seeds' => ROOT . 'Plugin/Manager/config/Seeds',
        ];
        $config = clone $this->config;
        $config['paths'] = $paths;

        return $config;
    }

    /**
     * Prepares an environment for cross DBMS functional tests.
     *
     * @param array $paths The paths config to override.
     * @return \Migrations\Db\Adapter\AdapterInterface
     */
    protected function prepareEnvironment(array $paths = []): AdapterInterface
    {
        $configArray = $this->getConfigArray();

        // override paths as needed
        if ($paths) {
            $configArray['paths'] = $paths + $configArray['paths'];
        }
        // Emulate the results of Util::parseDsn()
        $connectionConfig = ConnectionManager::getConfig('test');
        $adapter = $connectionConfig['scheme'] ?? null;
        $adapterConfig = [
            'adapter' => $adapter,
            'user' => $connectionConfig['username'],
            'pass' => $connectionConfig['password'],
            'host' => $connectionConfig['host'],
            'name' => $connectionConfig['database'],
        ];

        $configArray['environments']['production'] = $adapterConfig;
        $this->manager->setConfig(new Config($configArray));

        $adapter = $this->manager->getEnvironment('production')->getAdapter();

        // ensure the database is empty
        if ($adapterConfig['adapter'] === 'postgres') {
            $adapter->dropSchema('public');
            $adapter->createSchema('public');
        } elseif ($adapterConfig['name'] !== ':memory:') {
            $adapter->dropDatabase($adapterConfig['name']);
            $adapter->createDatabase($adapterConfig['name']);
        }
        $adapter->disconnect();

        return $adapter;
    }

    public function testInstantiation()
    {
        $this->assertInstanceOf(
            'Symfony\Component\Console\Output\StreamOutput',
            $this->manager->getOutput()
        );
    }

    public function testEnvironmentInheritsDataDomainOptions()
    {
        foreach ($this->config->getEnvironments() as $name => $opts) {
            $env = $this->manager->getEnvironment($name);
            $this->assertArrayHasKey('data_domain', $env->getOptions());
        }
    }

    public function testPrintStatusMethod()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->once())
                ->method('getVersionLog')
                ->will($this->returnValue(
                    [
                        '20120111235330' =>
                            [
                                'version' => '20120111235330',
                                'start_time' => '2012-01-11 23:53:36',
                                'end_time' => '2012-01-11 23:53:37',
                                'migration_name' => '',
                                'breakpoint' => '0',
                            ],
                        '20120116183504' =>
                            [
                                'version' => '20120116183504',
                                'start_time' => '2012-01-16 18:35:40',
                                'end_time' => '2012-01-16 18:35:41',
                                'migration_name' => '',
                                'breakpoint' => '0',
                            ],
                    ]
                ));

        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->getOutput()->setDecorated(false);
        $return = $this->manager->printStatus('mockenv');
        $expected = [
          [
            'status' => 'up',
            'id' => 20120111235330,
            'name' => 'TestMigration',
          ],
          [
            'status' => 'up',
            'id' => 20120116183504,
            'name' => 'TestMigration2',
          ],
        ];
        $this->assertEquals($expected, $return);
    }

    public function testPrintStatusMethodJsonFormat()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->once())
                ->method('getVersionLog')
                ->will($this->returnValue(
                    [
                        '20120111235330' =>
                            [
                                'version' => '20120111235330',
                                'start_time' => '2012-01-11 23:53:36',
                                'end_time' => '2012-01-11 23:53:37',
                                'migration_name' => '',
                                'breakpoint' => '0',
                            ],
                        '20120116183504' =>
                            [
                                'version' => '20120116183504',
                                'start_time' => '2012-01-16 18:35:40',
                                'end_time' => '2012-01-16 18:35:41',
                                'migration_name' => '',
                                'breakpoint' => '0',
                            ],
                    ]
                ));
        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->getOutput()->setDecorated(false);
        $return = $this->manager->printStatus('mockenv', AbstractCommand::FORMAT_JSON);
        $expected = [
            [
              'status' => 'up',
              'id' => 20120111235330,
              'name' => 'TestMigration',
            ],
            [
              'status' => 'up',
              'id' => 20120116183504,
              'name' => 'TestMigration2',
            ],
        ];
        $this->assertSame($expected, $return);
    }

    public function testPrintStatusMethodWithBreakpointSet()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->once())
                ->method('getVersionLog')
                ->will($this->returnValue(
                    [
                        '20120111235330' =>
                            [
                                'version' => '20120111235330',
                                'start_time' => '2012-01-11 23:53:36',
                                'end_time' => '2012-01-11 23:53:37',
                                'migration_name' => '',
                                'breakpoint' => '1',
                            ],
                        '20120116183504' =>
                            [
                                'version' => '20120116183504',
                                'start_time' => '2012-01-16 18:35:40',
                                'end_time' => '2012-01-16 18:35:41',
                                'migration_name' => '',
                                'breakpoint' => '0',
                            ],
                    ]
                ));

        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->getOutput()->setDecorated(false);
        $return = $this->manager->printStatus('mockenv');
        $expected = [
          [
            'status' => 'up',
            'id' => 20120111235330,
            'name' => 'TestMigration',
          ],
          [
            'status' => 'up',
            'id' => 20120116183504,
            'name' => 'TestMigration2',
          ],
        ];
        $this->assertEquals($expected, $return);
    }

    public function testPrintStatusMethodWithNoMigrations()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();

        // override the migrations directory to an empty one
        $configArray = $this->getConfigArray();
        $configArray['paths']['migrations'] = ROOT . '/config/Nomigrations';
        $config = new Config($configArray);

        $this->manager->setConfig($config);
        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->getOutput()->setDecorated(false);
        $return = $this->manager->printStatus('mockenv');
        $this->assertEquals([], $return);
    }

    public function testPrintStatusMethodWithMissingMigrations()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->once())
                ->method('getVersionLog')
                ->will($this->returnValue(
                    [
                        '20120103083300' =>
                            [
                                'version' => '20120103083300',
                                'start_time' => '2012-01-11 23:53:36',
                                'end_time' => '2012-01-11 23:53:37',
                                'migration_name' => '',
                                'breakpoint' => '0',
                            ],
                        '20120815145812' =>
                            [
                                'version' => '20120815145812',
                                'start_time' => '2012-01-16 18:35:40',
                                'end_time' => '2012-01-16 18:35:41',
                                'migration_name' => 'Example',
                                'breakpoint' => '0',
                            ],
                    ]
                ));

        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->getOutput()->setDecorated(false);
        $return = $this->manager->printStatus('mockenv');
        $expected = [
            [
              'missing' => true,
              'status' => 'up',
              'id' => '20120103083300',
              'name' => '',
            ],
            [
              'status' => 'down',
              'id' => 20120111235330,
              'name' => 'TestMigration',
            ],
            [
              'status' => 'down',
              'id' => 20120116183504,
              'name' => 'TestMigration2',
            ],
            [
              'missing' => true,
              'status' => 'up',
              'id' => '20120815145812',
              'name' => 'Example',
            ],
        ];
        $this->assertEquals($expected, $return);
    }

    public function testPrintStatusMethodWithMissingLastMigration()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->once())
                ->method('getVersionLog')
                ->will($this->returnValue(
                    [
                        '20120111235330' =>
                            [
                                'version' => '20120111235330',
                                'start_time' => '2012-01-16 18:35:40',
                                'end_time' => '2012-01-16 18:35:41',
                                'migration_name' => '',
                                'breakpoint' => 0,
                            ],
                        '20120116183504' =>
                            [
                                'version' => '20120116183504',
                                'start_time' => '2012-01-16 18:35:40',
                                'end_time' => '2012-01-16 18:35:41',
                                'migration_name' => '',
                                'breakpoint' => '0',
                            ],
                        '20120120145114' =>
                            [
                                'version' => '20120120145114',
                                'start_time' => '2012-01-20 14:51:14',
                                'end_time' => '2012-01-20 14:51:14',
                                'migration_name' => 'Example',
                                'breakpoint' => '0',
                            ],
                    ]
                ));

        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->getOutput()->setDecorated(false);
        $return = $this->manager->printStatus('mockenv');
        $expected = [
            [
              'status' => 'up',
              'id' => 20120111235330,
              'name' => 'TestMigration',
            ],
            [
              'status' => 'up',
              'id' => 20120116183504,
              'name' => 'TestMigration2',
            ],
            [
              'missing' => true,
              'status' => 'up',
              'id' => '20120120145114',
              'name' => 'Example',
            ],
        ];
        $this->assertEquals($expected, $return);
    }

    public function testPrintStatusMethodWithMissingMigrationsAndBreakpointSet()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->once())
                ->method('getVersionLog')
                ->will($this->returnValue(
                    [
                        '20120103083300' =>
                            [
                                'version' => '20120103083300',
                                'start_time' => '2012-01-11 23:53:36',
                                'end_time' => '2012-01-11 23:53:37',
                                'migration_name' => '',
                                'breakpoint' => '1',
                            ],
                        '20120815145812' =>
                            [
                                'version' => '20120815145812',
                                'start_time' => '2012-01-16 18:35:40',
                                'end_time' => '2012-01-16 18:35:41',
                                'migration_name' => 'Example',
                                'breakpoint' => '0',
                            ],
                    ]
                ));

        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->getOutput()->setDecorated(false);
        $return = $this->manager->printStatus('mockenv');
        $expected = [
            [
              'missing' => true,
              'status' => 'up',
              'id' => '20120103083300',
              'name' => '',
            ],
            [
              'status' => 'down',
              'id' => 20120111235330,
              'name' => 'TestMigration',
            ],
            [
              'status' => 'down',
              'id' => 20120116183504,
              'name' => 'TestMigration2',
            ],
            [
              'missing' => true,
              'status' => 'up',
              'id' => '20120815145812',
              'name' => 'Example',
            ],
        ];
        $this->assertEquals($expected, $return);
    }

    public function testPrintStatusMethodWithDownMigrations()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->once())
                ->method('getVersionLog')
                ->will($this->returnValue([
                    '20120111235330' => [
                        'version' => '20120111235330',
                        'start_time' => '2012-01-16 18:35:40',
                        'end_time' => '2012-01-16 18:35:41',
                        'migration_name' => '',
                        'breakpoint' => 0,
                    ]]));

        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->getOutput()->setDecorated(false);
        $return = $this->manager->printStatus('mockenv');
        $expected = [
            [
              'status' => 'up',
              'id' => 20120111235330,
              'name' => 'TestMigration',
            ],
            [
              'status' => 'down',
              'id' => 20120116183504,
              'name' => 'TestMigration2',
            ],
        ];
        $this->assertEquals($expected, $return);
    }

    public function testPrintStatusMethodWithMissingAndDownMigrations()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->once())
                ->method('getVersionLog')
                ->will($this->returnValue([
                    '20120111235330' =>
                        [
                            'version' => '20120111235330',
                            'start_time' => '2012-01-16 18:35:40',
                            'end_time' => '2012-01-16 18:35:41',
                            'migration_name' => '',
                            'breakpoint' => 0,
                        ],
                    '20120103083300' =>
                        [
                            'version' => '20120103083300',
                            'start_time' => '2012-01-11 23:53:36',
                            'end_time' => '2012-01-11 23:53:37',
                            'migration_name' => '',
                            'breakpoint' => 0,
                        ],
                    '20120815145812' =>
                        [
                            'version' => '20120815145812',
                            'start_time' => '2012-01-16 18:35:40',
                            'end_time' => '2012-01-16 18:35:41',
                            'migration_name' => 'Example',
                            'breakpoint' => 0,
                        ]]));

        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->getOutput()->setDecorated(false);
        $return = $this->manager->printStatus('mockenv');
        $expected = [
            [
              'missing' => true,
              'status' => 'up',
              'id' => '20120103083300',
              'name' => '',
            ],
            [
              'status' => 'up',
              'id' => 20120111235330,
              'name' => 'TestMigration',
            ],
            [
              'status' => 'down',
              'id' => 20120116183504,
              'name' => 'TestMigration2',
            ],
            [
              'missing' => true,
              'status' => 'up',
              'id' => '20120815145812',
              'name' => 'Example',
            ],
        ];
        $this->assertEquals($expected, $return);
    }

    public function testGetMigrationsWithDuplicateMigrationVersions()
    {
        $config = new Config(['paths' => ['migrations' => ROOT . '/config/Duplicateversions']]);
        $manager = new Manager($config, $this->input, $this->output);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Duplicate migration/');
        $this->expectExceptionMessageMatches('/20120111235330_duplicate_migration_2.php" has the same version as "20120111235330"/');
        $manager->getMigrations('mockenv');
    }

    public function testGetMigrationsWithDuplicateMigrationNames()
    {
        $config = new Config(['paths' => ['migrations' => ROOT . '/config/Duplicatenames']]);
        $manager = new Manager($config, $this->input, $this->output);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Migration "20120111235331_duplicate_migration_name.php" has the same name as "20120111235330_duplicate_migration_name.php"');

        $manager->getMigrations('mockenv');
    }

    public function testGetMigrationsWithInvalidMigrationClassName()
    {
        $config = new Config(['paths' => ['migrations' => ROOT . '/config/Invalidclassname']]);
        $manager = new Manager($config, $this->input, $this->output);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Could not find class "InvalidClass" in file/');
        $this->expectExceptionMessageMatches('/20120111235330_invalid_class.php/');

        $manager->getMigrations('mockenv');
    }

    public function testGettingAValidEnvironment()
    {
        $this->assertInstanceOf(
            Environment::class,
            $this->manager->getEnvironment('production')
        );
    }

    /**
     * Test that migrating by date chooses the correct
     * migration to point to.
     *
     * @dataProvider migrateDateDataProvider
     * @param string[] $availableMigrations
     * @param string $dateString
     * @param string $expectedMigration
     * @param string $message
     */
    public function testMigrationsByDate(array $availableMigrations, $dateString, $expectedMigration, $message)
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        if (is_null($expectedMigration)) {
            $envStub->expects($this->never())
                    ->method('getVersions');
        } else {
            $envStub->expects($this->once())
                    ->method('getVersions')
                    ->will($this->returnValue($availableMigrations));
        }
        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->migrateToDateTime('mockenv', new DateTime($dateString));
        rewind($this->manager->getOutput()->getStream());
        $output = stream_get_contents($this->manager->getOutput()->getStream());
        if (is_null($expectedMigration)) {
            $this->assertEmpty($output, $message);
        } else {
            $this->assertStringContainsString($expectedMigration, $output, $message);
        }
    }

    /**
     * Test that rollbacking to version chooses the correct
     * migration to point to.
     *
     * @dataProvider rollbackToVersionDataProvider
     */
    public function testRollbackToVersion($availableRollbacks, $version, $expectedOutput)
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->any())
            ->method('getVersionLog')
            ->will($this->returnValue($availableRollbacks));

        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->rollback('mockenv', $version);
        rewind($this->manager->getOutput()->getStream());
        $output = stream_get_contents($this->manager->getOutput()->getStream());
        if (is_null($expectedOutput)) {
            $this->assertEquals('No migrations to rollback' . PHP_EOL, $output);
        } else {
            if (is_string($expectedOutput)) {
                $expectedOutput = [$expectedOutput];
            }

            foreach ($expectedOutput as $expectedLine) {
                $this->assertStringContainsString($expectedLine, $output);
            }
        }
    }

    /**
     * Test that rollbacking to date chooses the correct
     * migration to point to.
     *
     * @dataProvider rollbackToDateDataProvider
     */
    public function testRollbackToDate($availableRollbacks, $version, $expectedOutput)
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->any())
            ->method('getVersionLog')
            ->will($this->returnValue($availableRollbacks));

        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->rollback('mockenv', $version, false, false);
        rewind($this->manager->getOutput()->getStream());
        $output = stream_get_contents($this->manager->getOutput()->getStream());
        if (is_null($expectedOutput)) {
            $this->assertEquals('No migrations to rollback' . PHP_EOL, $output);
        } else {
            if (is_string($expectedOutput)) {
                $expectedOutput = [$expectedOutput];
            }

            foreach ($expectedOutput as $expectedLine) {
                $this->assertStringContainsString($expectedLine, $output);
            }
        }
    }

    /**
     * Test that rollbacking to version by execution time chooses the correct
     * migration to point to.
     *
     * @dataProvider rollbackToVersionByExecutionTimeDataProvider
     */
    public function testRollbackToVersionByExecutionTime($availableRollbacks, $version, $expectedOutput)
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->any())
            ->method('getVersionLog')
            ->will($this->returnValue($availableRollbacks));

        // get a manager with a config whose version order is set to execution time
        $configArray = $this->getConfigArray();
        $configArray['version_order'] = Config::VERSION_ORDER_EXECUTION_TIME;
        $config = new Config($configArray);
        $this->input = new ArrayInput([]);
        $this->output = new StreamOutput(fopen('php://memory', 'a', false));
        $this->output->setDecorated(false);

        $this->manager = new Manager($config, $this->input, $this->output);
        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->rollback('mockenv', $version);
        rewind($this->manager->getOutput()->getStream());
        $output = stream_get_contents($this->manager->getOutput()->getStream());

        if (is_null($expectedOutput)) {
            $this->assertEmpty($output);
        } else {
            if (is_string($expectedOutput)) {
                $expectedOutput = [$expectedOutput];
            }

            foreach ($expectedOutput as $expectedLine) {
                $this->assertStringContainsString($expectedLine, $output);
            }
        }
    }

    /**
     * Test that rollbacking to version by migration name chooses the correct
     * migration to point to.
     *
     * @dataProvider rollbackToVersionByExecutionTimeDataProvider
     */
    public function testRollbackToVersionByName($availableRollbacks, $version, $expectedOutput)
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->any())
            ->method('getVersionLog')
            ->will($this->returnValue($availableRollbacks));

        // get a manager with a config whose version order is set to execution time
        $configArray = $this->getConfigArray();
        $configArray['version_order'] = Config::VERSION_ORDER_EXECUTION_TIME;
        $config = new Config($configArray);
        $this->input = new ArrayInput([]);
        $this->output = new StreamOutput(fopen('php://memory', 'a', false));
        $this->output->setDecorated(false);

        $this->manager = new Manager($config, $this->input, $this->output);
        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->rollback('mockenv', $availableRollbacks[$version]['migration_name'] ?? $version);
        rewind($this->manager->getOutput()->getStream());
        $output = stream_get_contents($this->manager->getOutput()->getStream());

        if (is_null($expectedOutput)) {
            $this->assertEmpty($output);
        } else {
            if (is_string($expectedOutput)) {
                $expectedOutput = [$expectedOutput];
            }

            foreach ($expectedOutput as $expectedLine) {
                $this->assertStringContainsString($expectedLine, $output);
            }
        }
    }

    /**
     * Test that rollbacking to date by execution time chooses the correct
     * migration to point to.
     *
     * @dataProvider rollbackToDateByExecutionTimeDataProvider
     */
    public function testRollbackToDateByExecutionTime($availableRollbacks, $date, $expectedOutput)
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->any())
            ->method('getVersionLog')
            ->will($this->returnValue($availableRollbacks));

        // get a manager with a config whose version order is set to execution time
        $configArray = $this->getConfigArray();
        $configArray['version_order'] = Config::VERSION_ORDER_EXECUTION_TIME;
        $config = new Config($configArray);
        $this->input = new ArrayInput([]);
        $this->output = new StreamOutput(fopen('php://memory', 'a', false));
        $this->output->setDecorated(false);

        $this->manager = new Manager($config, $this->input, $this->output);
        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->rollback('mockenv', $date, false, false);
        rewind($this->manager->getOutput()->getStream());
        $output = stream_get_contents($this->manager->getOutput()->getStream());

        if (is_null($expectedOutput)) {
            $this->assertEquals('No migrations to rollback' . PHP_EOL, $output);
        } else {
            if (is_string($expectedOutput)) {
                $expectedOutput = [$expectedOutput];
            }

            foreach ($expectedOutput as $expectedLine) {
                $this->assertStringContainsString($expectedLine, $output);
            }
        }
    }

    public function testRollbackToVersionWithSingleMigrationDoesNotFail()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->any())
                ->method('getVersionLog')
                ->will($this->returnValue([
                    '20120111235330' => ['version' => '20120111235330', 'migration' => '', 'breakpoint' => 0],
                ]));
        $envStub->expects($this->any())
                ->method('getVersions')
                ->will($this->returnValue([20120111235330]));

        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->rollback('mockenv');
        rewind($this->manager->getOutput()->getStream());
        $output = stream_get_contents($this->manager->getOutput()->getStream());
        $this->assertStringContainsString('== 20120111235330 TestMigration: reverting', $output);
        $this->assertStringContainsString('== 20120111235330 TestMigration: reverted', $output);
        $this->assertStringNotContainsString('No migrations to rollback', $output);
        $this->assertStringNotContainsString('Undefined offset: -1', $output);
    }

    public function testRollbackToVersionWithTwoMigrations()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->any())
                ->method('getVersionLog')
                ->will(
                    $this->returnValue(
                        [
                            '20120111235330' => ['version' => '20120111235330', 'migration' => '', 'breakpoint' => 0],
                            '20120116183504' => ['version' => '20120815145812', 'migration' => '', 'breakpoint' => 0],
                        ]
                    )
                );
        $envStub->expects($this->any())
                ->method('getVersions')
                ->will(
                    $this->returnValue(
                        [
                            20120111235330,
                            20120116183504,
                        ]
                    )
                );

        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->rollback('mockenv');
        rewind($this->manager->getOutput()->getStream());
        $output = stream_get_contents($this->manager->getOutput()->getStream());
        $this->assertStringNotContainsString('== 20120111235330 TestMigration: reverting', $output);
    }

    /**
     * Test that rollbacking last migration
     *
     * @dataProvider rollbackLastDataProvider
     */
    public function testRollbackLast($availableRolbacks, $versionOrder, $expectedOutput)
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->any())
            ->method('getVersionLog')
            ->will($this->returnValue($availableRolbacks));

        // get a manager with a config whose version order is set to execution time
        $configArray = $this->getConfigArray();
        $configArray['version_order'] = $versionOrder;
        $config = new Config($configArray);
        $this->input = new ArrayInput([]);
        $this->output = new StreamOutput(fopen('php://memory', 'a', false));
        $this->output->setDecorated(false);
        $this->manager = new Manager($config, $this->input, $this->output);
        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->rollback('mockenv', null);
        rewind($this->manager->getOutput()->getStream());
        $output = stream_get_contents($this->manager->getOutput()->getStream());
        if (is_null($expectedOutput)) {
            $this->assertEquals('No migrations to rollback' . PHP_EOL, $output);
        } else {
            if (is_string($expectedOutput)) {
                $expectedOutput = [$expectedOutput];
            }

            foreach ($expectedOutput as $expectedLine) {
                $this->assertStringContainsString($expectedLine, $output);
            }
        }
    }

    /**
     * Migration lists, dates, and expected migrations to point to.
     *
     * @return array
     */
    public static function migrateDateDataProvider()
    {
        return [
            [['20120111235330', '20120116183504'], '20120118', '20120116183504', 'Failed to migrate all migrations when migrate to date is later than all the migrations'],
            [['20120111235330', '20120116183504'], '20120115', '20120111235330', 'Failed to migrate 1 migration when the migrate to date is between 2 migrations'],
        ];
    }

    /**
     * Migration lists, dates, and expected migration version to rollback to.
     *
     * @return array
     */
    public static function rollbackToDateDataProvider()
    {
        return [

            // No breakpoints set

            'Rollback to date which is later than all migrations - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20130118000000',
                    null,
                ],
            'Rollback to date of the most recent migration - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20120116183504',
                    null,
                ],
            'Rollback to date between 2 migrations - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20120115',
                    '== 20120116183504 TestMigration2: reverted',
                ],
            'Rollback to date of the oldest migration - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20120111235330',
                    '== 20120116183504 TestMigration2: reverted',
                ],
            'Rollback to date before all the migrations - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20110115',
                    ['== 20120116183504 TestMigration2: reverted', '== 20120111235330 TestMigration: reverted'],
                ],

            // Breakpoint set on first migration

            'Rollback to date which is later than all migrations - breakpoint set on first migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20130118000000',
                    null,
                ],
            'Rollback to date of the most recent migration - breakpoint set on first migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20120116183504',
                    null,
                ],
            'Rollback to date between 2 migrations - breakpoint set on first migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20120115',
                    '== 20120116183504 TestMigration2: reverted',
                ],
            'Rollback to date of the oldest migration - breakpoint set on first migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20120111235330',
                    '== 20120116183504 TestMigration2: reverted',
                ],
            'Rollback to date before all the migrations - breakpoint set on first migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20110115',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],

            // Breakpoint set on last migration

            'Rollback to date which is later than all migrations - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20130118000000',
                    null,
                ],
            'Rollback to date of the most recent migration - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20120116183504',
                    null,
                ],
            'Rollback to date between 2 migrations - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20120115000000',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to date of the oldest migration - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20120111235330',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to date before all the migrations - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20110115000000',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],

            // Breakpoint set on all migrations

            'Rollback to date which is later than all migrations - breakpoint set on all migrations' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20130118000000',
                    null,
                ],
            'Rollback to date of the most recent migration - breakpoint set on all migrations' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20120116183504',
                    null,
                ],
            'Rollback to date between 2 migrations - breakpoint set on all migrations' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20120115000000',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to date of the oldest migration - breakpoint set on all migrations' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20120111235330',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to date before all the migrations - breakpoint set on all migrations' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20110115000000',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
        ];
    }

    /**
     * Migration lists, dates, and expected migration version to rollback to.
     *
     * @return array
     */
    public static function rollbackToDateByExecutionTimeDataProvider()
    {
        return [

            // No breakpoints set

            'Rollback to date later than all migration start times when they were created in a different order than they were executed - no breakpoints set' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 0],
                    ],
                    '20131212000000',
                    null,
                ],
            'Rollback to date earlier than all migration start times when they were created in a different order than they were executed - no breakpoints set' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 0],
                    ],
                    '20111212000000',
                    ['== 20120111235330 TestMigration: reverted', '== 20120116183504 TestMigration2: reverted'],
                ],
            'Rollback to start time of first created version which was the last to be executed - no breakpoints set' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 0],
                    ],
                    '20120120235330',
                    null,
                ],
            'Rollback to start time of second created version which was the first to be executed - no breakpoints set' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 0],
                    ],
                    '20120117183504',
                    '== 20120111235330 TestMigration: reverted',
                ],
            'Rollback to date between the 2 migrations when they were created in a different order than they were executed - no breakpoints set' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 0],
                    ],
                    '20120118000000',
                    '== 20120111235330 TestMigration: reverted',
                ],
            'Rollback the last executed migration when the migrations were created in a different order than they were executed - no breakpoints set' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 0],
                    ],
                    null,
                    '== 20120111235330 TestMigration: reverted',
                ],

            // Breakpoint set on first/last created/executed migration

            'Rollback to date later than all migration start times when they were created in a different order than they were executed - breakpoints set on first created (and last executed) migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 1],
                    ],
                    '20131212000000',
                    null,
                ],
            'Rollback to date later than all migration start times when they were created in a different order than they were executed - breakpoints set on first executed (and last created) migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 0],
                    ],
                    '20131212000000',
                    null,
                ],
            'Rollback to date earlier than all migration start times when they were created in a different order than they were executed - breakpoints set on first created (and last executed) migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 1],
                    ],
                    '20111212000000',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to date earlier than all migration start times when they were created in a different order than they were executed - breakpoints set on first executed (and last created) migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 0],
                    ],
                    '20111212000000',
                    ['== 20120111235330 TestMigration: reverted', 'Breakpoint reached. Further rollbacks inhibited.'],
                ],
            'Rollback to start time of first created version which was the last to be executed - breakpoints set on first created (and last executed) migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 1],
                    ],
                    '20120120235330',
                    null,
                ],
            'Rollback to start time of first created version which was the last to be executed - breakpoints set on first executed (and last created) migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 0],
                    ],
                    '20120120235330',
                    null,
                ],
            'Rollback to start time of second created version which was the first to be executed - breakpoints set on first created (and last executed) migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 1],
                    ],
                    '20120117183504',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to start time of second created version which was the first to be executed - breakpoints set on first executed (and last created) migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 0],
                    ],
                    '20120117183504',
                    '== 20120111235330 TestMigration: reverted',
                ],
            'Rollback to date between the 2 migrations when they were created in a different order than they were executed - breakpoints set on first created (and last executed) migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 1],
                    ],
                    '20120118000000',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to date between the 2 migrations when they were created in a different order than they were executed - breakpoints set on first executed (and last created) migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 0],
                    ],
                    '20120118000000',
                    '== 20120111235330 TestMigration: reverted',
                ],
            'Rollback the last executed migration when the migrations were created in a different order than they were executed - breakpoints set on first created (and last executed) migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 1],
                    ],
                    null,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback the last executed migration when the migrations were created in a different order than they were executed - breakpoints set on first executed (and last created) migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 0],
                    ],
                    null,
                    '== 20120111235330 TestMigration: reverted',
                ],

            // Breakpoint set on all migration

            'Rollback to date later than all migration start times when they were created in a different order than they were executed - breakpoints set on all migrations' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 1],
                    ],
                    '20131212000000',
                    null,
                ],
            'Rollback to date earlier than all migration start times when they were created in a different order than they were executed - breakpoints set on all migrations' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 1],
                    ],
                    '20111212000000',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to start time of first created version which was the last to be executed - breakpoints set on all migrations' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 1],
                    ],
                    '20120120235330',
                    null,
                ],
            'Rollback to start time of second created version which was the first to be executed - breakpoints set on all migrations' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 1],
                    ],
                    '20120117183504',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to date between the 2 migrations when they were created in a different order than they were executed - breakpoints set on all migrations' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 1],
                    ],
                    '20120118000000',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback the last executed migration when the migrations were created in a different order than they were executed - breakpoints set on all migrations' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'breakpoint' => 1],
                    ],
                    null,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
        ];
    }

    /**
     * Migration lists, dates, and expected output.
     *
     * @return array
     */
    public static function rollbackToVersionDataProvider()
    {
        return [

            // No breakpoints set

            'Rollback to one of the versions - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20120111235330',
                    '== 20120116183504 TestMigration2: reverted',
                ],
            'Rollback to the latest version - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20120116183504',
                    null,
                ],
            'Rollback all versions (ie. rollback to version 0) - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '0',
                    ['== 20120111235330 TestMigration: reverted', '== 20120116183504 TestMigration2: reverted'],
                ],
            'Rollback last version - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    null,
                    '== 20120116183504 TestMigration2: reverted',
                ],
            'Rollback to non-existing version - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20121225000000',
                    'Target version (20121225000000) not found',
                ],
            'Rollback to missing version - no breakpoints set' =>
                [
                    [
                        '20111225000000' => ['version' => '20111225000000', 'migration_name' => '', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20111225000000',
                    'Target version (20111225000000) not found',
                ],

            // Breakpoint set on first migration

            'Rollback to one of the versions - breakpoint set on first migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20120111235330',
                    '== 20120116183504 TestMigration2: reverted',
                ],
            'Rollback to the latest version - breakpoint set on first migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20120116183504',
                    null,
                ],
            'Rollback all versions (ie. rollback to version 0) - breakpoint set on first migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '0',
                    '== 20120116183504 TestMigration2: reverted',
                ],
            'Rollback last version - breakpoint set on first migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    null,
                    '== 20120116183504 TestMigration2: reverted',
                ],
            'Rollback to non-existing version - breakpoint set on first migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20121225000000',
                    'Target version (20121225000000) not found',
                ],
            'Rollback to missing version - breakpoint set on first migration' =>
                [
                    [
                        '20111225000000' => ['version' => '20111225000000', 'migration_name' => '', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 0],
                    ],
                    '20111225000000',
                    'Target version (20111225000000) not found',
                ],

            // Breakpoint set on last migration

            'Rollback to one of the versions - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20120111235330',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to the latest version - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20120116183504',
                    null,
                ],
            'Rollback all versions (ie. rollback to version 0) - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '0',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback last version - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    null,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to non-existing version - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20121225000000',
                    'Target version (20121225000000) not found',
                ],
            'Rollback to missing version - breakpoint set on last migration' =>
                [
                    [
                        '20111225000000' => ['version' => '20111225000000', 'migration_name' => '', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20111225000000',
                    'Target version (20111225000000) not found',
                ],

            // Breakpoint set on all migrations

            'Rollback to one of the versions - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20120111235330',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to the latest version - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20120116183504',
                    null,
                ],
            'Rollback all versions (ie. rollback to version 0) - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '0',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback last version - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    null,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to non-existing version - breakpoint set on last migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20121225000000',
                    'Target version (20121225000000) not found',
                ],
            'Rollback to missing version - breakpoint set on last migration' =>
                [
                    [
                        '20111225000000' => ['version' => '20111225000000', 'migration_name' => '', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'migration_name' => '', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'migration_name' => '', 'breakpoint' => 1],
                    ],
                    '20111225000000',
                    'Target version (20111225000000) not found',
                ],
        ];
    }

    public static function rollbackToVersionByExecutionTimeDataProvider()
    {
        return [

            // No breakpoints set

            'Rollback to first created version with was also the first to be executed - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120111235330',
                    '== 20120116183504 TestMigration2: reverted',
                ],
            'Rollback to last created version which was also the last to be executed - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120116183504',
                    'No migrations to rollback',
                ],
            'Rollback all versions (ie. rollback to version 0) - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '0',
                    ['== 20120111235330 TestMigration: reverted', '== 20120116183504 TestMigration2: reverted'],
                ],
            'Rollback to second created version which was the first to be executed - no breakpoints set' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-10 18:35:04', 'end_time' => '2012-01-10 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120116183504',
                    '== 20120111235330 TestMigration: reverted',
                ],
            'Rollback to first created version which was the second to be executed - no breakpoints set' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120111235330',
                    'No migrations to rollback',
                ],
            'Rollback last executed version which was also the last created version - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    null,
                    '== 20120116183504 TestMigration2: reverted',
                ],
            'Rollback last executed version which was the first created version - no breakpoints set' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    null,
                    '== 20120111235330 TestMigration: reverted',
                ],
            'Rollback to non-existing version - no breakpoints set' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '20121225000000',
                    'Target version (20121225000000) not found',
                ],
            'Rollback to missing version - no breakpoints set' =>
                [
                    [
                        '20111225000000' => ['version' => '20111225000000', 'start_time' => '2011-12-25 00:00:00', 'end_time' => '2011-12-25 00:00:00', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration3'],
                    ],
                    '20121225000000',
                    'Target version (20121225000000) not found',
                ],

            // Breakpoint set on first migration

            'Rollback to first created version with was also the first to be executed - breakpoint set on first (executed and created) migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120111235330',
                    '== 20120116183504 TestMigration2: reverted',
                ],
            'Rollback to last created version which was also the last to be executed - breakpoint set on first (executed and created) migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120116183504',
                    'No migrations to rollback',
                ],
            'Rollback all versions (ie. rollback to version 0) - breakpoint set on first (executed and created) migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '0',
                    ['== 20120116183504 TestMigration2: reverted', 'Breakpoint reached. Further rollbacks inhibited.'],
                ],
            'Rollback to second created version which was the first to be executed - breakpoint set on first executed migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-10 18:35:04', 'end_time' => '2012-01-10 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120116183504',
                    '== 20120111235330 TestMigration: reverted',
                ],
            'Rollback to second created version which was the first to be executed - breakpoint set on first created migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-10 18:35:04', 'end_time' => '2012-01-10 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120116183504',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to first created version which was the second to be executed - breakpoint set on first executed migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120111235330',
                    'No migrations to rollback',
                ],
            'Rollback to first created version which was the second to be executed - breakpoint set on first created migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120111235330',
                    'No migrations to rollback',
                ],
            'Rollback last executed version which was also the last created version - breakpoint set on first (executed and created) migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    null,
                    '== 20120116183504 TestMigration2: reverted',
                ],
            'Rollback last executed version which was the first created version - breakpoint set on first executed migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    null,
                    '== 20120111235330 TestMigration: reverted',
                ],
            'Rollback last executed version which was the first created version - breakpoint set on first created migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    null,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to non-existing version - breakpoint set on first executed migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '20121225000000',
                    'Target version (20121225000000) not found',
                ],
            'Rollback to missing version - breakpoint set on first executed migration' =>
                [
                    [
                        '20111225000000' => ['version' => '20111225000000', 'start_time' => '2011-12-25 00:00:00', 'end_time' => '2011-12-25 00:00:00', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration3'],
                    ],
                    '20121225000000',
                    'Target version (20121225000000) not found',
                ],

            // Breakpoint set on last migration

            'Rollback to first created version with was also the first to be executed - breakpoint set on last (executed and created) migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120111235330',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to last created version which was also the last to be executed - breakpoint set on last (executed and created) migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120116183504',
                    'No migrations to rollback',
                ],
            'Rollback all versions (ie. rollback to version 0) - breakpoint set on last (executed and created) migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '0',
                    ['Breakpoint reached. Further rollbacks inhibited.'],
                ],
            'Rollback to second created version which was the first to be executed - breakpoint set on last executed migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-10 18:35:04', 'end_time' => '2012-01-10 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120116183504',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to second created version which was the first to be executed - breakpoint set on last created migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-10 18:35:04', 'end_time' => '2012-01-10 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120116183504',
                    '== 20120111235330 TestMigration: reverted',
                ],
            'Rollback to first created version which was the second to be executed - breakpoint set on last executed migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120111235330',
                    'No migrations to rollback',
                ],
            'Rollback to first created version which was the second to be executed - breakpoint set on last created migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120111235330',
                    'No migrations to rollback',
                ],
            'Rollback last executed version which was also the last created version - breakpoint set on last (executed and created) migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    null,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback last executed version which was the first created version - breakpoint set on last executed migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    null,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback last executed version which was the first created version - breakpoint set on last created migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                    ],
                    null,
                    '== 20120111235330 TestMigration: reverted',
                ],
            'Rollback to non-existing version - breakpoint set on last executed migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '20121225000000',
                    'Target version (20121225000000) not found',
                ],
            'Rollback to missing version - breakpoint set on last executed migration' =>
                [
                    [
                        '20111225000000' => ['version' => '20111225000000', 'start_time' => '2011-12-25 00:00:00', 'end_time' => '2011-12-25 00:00:00', 'breakpoint' => 0, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 0, 'migration_name' => 'TestMigration2'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration3'],
                    ],
                    '20121225000000',
                    'Target version (20121225000000) not found',
                ],

            // Breakpoint set on all migrations

            'Rollback to first created version with was also the first to be executed - breakpoint set on all migrations' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120111235330',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to last created version which was also the last to be executed - breakpoint set on all migrations' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120116183504',
                    'No migrations to rollback',
                ],
            'Rollback all versions (ie. rollback to version 0) - breakpoint set on all migrations' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '0',
                    ['Breakpoint reached. Further rollbacks inhibited.'],
                ],
            'Rollback to second created version which was the first to be executed - breakpoint set on all migrations' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-10 18:35:04', 'end_time' => '2012-01-10 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120116183504',
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to first created version which was the second to be executed - breakpoint set on all migrations' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '20120111235330',
                    'No migrations to rollback',
                ],
            'Rollback last executed version which was also the last created version - breakpoint set on all migrations' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'end_time' => '2012-01-12 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    null,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback last executed version which was the first created version - breakpoint set on all migrations' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    null,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            'Rollback to non-existing version - breakpoint set on all migrations' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                    ],
                    '20121225000000',
                    'Target version (20121225000000) not found',
                ],
            'Rollback to missing version - breakpoint set on all migrations' =>
                [
                    [
                        '20111225000000' => ['version' => '20111225000000', 'start_time' => '2011-12-25 00:00:00', 'end_time' => '2011-12-25 00:00:00', 'breakpoint' => 1, 'migration_name' => 'TestMigration1'],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-17 18:35:04', 'end_time' => '2012-01-17 18:35:04', 'breakpoint' => 1, 'migration_name' => 'TestMigration2'],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-20 23:53:30', 'end_time' => '2012-01-20 23:53:30', 'breakpoint' => 1, 'migration_name' => 'TestMigration3'],
                    ],
                    '20121225000000',
                    'Target version (20121225000000) not found',
                ],
        ];
    }

    /**
     * Migration lists, version order configuration and expected output.
     *
     * @return array
     */
    public static function rollbackLastDataProvider()
    {
        return [

            // No breakpoints set

            'Rollback to last migration with creation time version ordering - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-16 18:35:04', 'breakpoint' => 0],
                    ],
                    Config::VERSION_ORDER_CREATION_TIME,
                    '== 20120116183504 TestMigration2: reverted',
                ],

            'Rollback to last migration with execution time version ordering - no breakpoints set' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-10 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 0],
                    ],
                    Config::VERSION_ORDER_EXECUTION_TIME,
                    '== 20120111235330 TestMigration: reverted',
                ],

            'Rollback to last migration with missing last migration and creation time version ordering - no breakpoints set' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-16 18:35:04', 'breakpoint' => 0],
                        '20130101225232' => ['version' => '20130101225232', 'start_time' => '2013-01-01 22:52:32', 'breakpoint' => 0],
                    ],
                    Config::VERSION_ORDER_CREATION_TIME,
                    '== 20120116183504 TestMigration2: reverted',
                ],

            'Rollback to last migration with missing last migration and execution time version ordering - no breakpoints set' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-10 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 0],
                        '20130101225232' => ['version' => '20130101225232', 'start_time' => '2013-01-01 22:52:32', 'breakpoint' => 0],
                    ],
                    Config::VERSION_ORDER_EXECUTION_TIME,
                    '== 20120111235330 TestMigration: reverted',
                ],

            // Breakpoint set on last migration

            'Rollback to last migration with creation time version ordering - breakpoint set on last created migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-16 18:35:04', 'breakpoint' => 1],
                    ],
                    Config::VERSION_ORDER_CREATION_TIME,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],

            'Rollback to last migration with creation time version ordering - breakpoint set on last executed migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-16 18:35:04', 'breakpoint' => 0],
                    ],
                    Config::VERSION_ORDER_CREATION_TIME,
                    '== 20120116183504 TestMigration2: reverted',
                ],

            'Rollback to last migration with missing last migration and creation time version ordering - breakpoint set on last non-missing created migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-16 18:35:04', 'breakpoint' => 1],
                        '20130101225232' => ['version' => '20130101225232', 'start_time' => '2013-01-01 22:52:32', 'breakpoint' => 0],
                    ],
                    Config::VERSION_ORDER_CREATION_TIME,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],

            'Rollback to last migration with missing last migration and execution time version ordering - breakpoint set on last non-missing executed migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-10 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 1],
                        '20130101225232' => ['version' => '20130101225232', 'start_time' => '2013-01-01 22:52:32', 'breakpoint' => 0],
                    ],
                    Config::VERSION_ORDER_EXECUTION_TIME,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],

            'Rollback to last migration with missing last migration and creation time version ordering - breakpoint set on missing migration' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 0],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-16 18:35:04', 'breakpoint' => 0],
                        '20130101225232' => ['version' => '20130101225232', 'start_time' => '2013-01-01 22:52:32', 'breakpoint' => 1],
                    ],
                    Config::VERSION_ORDER_CREATION_TIME,
                    '== 20120116183504 TestMigration2: reverted',
                ],

            'Rollback to last migration with missing last migration and execution time version ordering - breakpoint set on missing migration' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-10 18:35:04', 'breakpoint' => 0],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 0],
                        '20130101225232' => ['version' => '20130101225232', 'start_time' => '2013-01-01 22:52:32', 'breakpoint' => 1],
                    ],
                    Config::VERSION_ORDER_EXECUTION_TIME,
                    '== 20120111235330 TestMigration: reverted',
                ],

            // Breakpoint set on all migrations

            'Rollback to last migration with creation time version ordering - breakpoint set on all migrations' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-16 18:35:04', 'breakpoint' => 1],
                    ],
                    Config::VERSION_ORDER_CREATION_TIME,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],

            'Rollback to last migration with creation time version ordering - breakpoint set on all migrations' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-16 18:35:04', 'breakpoint' => 1],
                    ],
                    Config::VERSION_ORDER_CREATION_TIME,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],

            'Rollback to last migration with missing last migration and creation time version ordering - breakpoint set on all migrations' =>
                [
                    [
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 1],
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-16 18:35:04', 'breakpoint' => 1],
                        '20130101225232' => ['version' => '20130101225232', 'start_time' => '2013-01-01 22:52:32', 'breakpoint' => 1],
                    ],
                    Config::VERSION_ORDER_CREATION_TIME,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],

            'Rollback to last migration with missing last migration and execution time version ordering - breakpoint set on all migrations' =>
                [
                    [
                        '20120116183504' => ['version' => '20120116183504', 'start_time' => '2012-01-10 18:35:04', 'breakpoint' => 1],
                        '20120111235330' => ['version' => '20120111235330', 'start_time' => '2012-01-12 23:53:30', 'breakpoint' => 1],
                        '20130101225232' => ['version' => '20130101225232', 'start_time' => '2013-01-01 22:52:32', 'breakpoint' => 1],
                    ],
                    Config::VERSION_ORDER_EXECUTION_TIME,
                    'Breakpoint reached. Further rollbacks inhibited.',
                ],
            ];
    }

    public function testExecuteSeedWorksAsExpected()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->seed('mockenv');
        rewind($this->manager->getOutput()->getStream());
        $output = stream_get_contents($this->manager->getOutput()->getStream());
        $this->assertStringContainsString('GSeeder', $output);
        $this->assertStringContainsString('PostSeeder', $output);
        $this->assertStringContainsString('UserSeeder', $output);
    }

    public function testExecuteASingleSeedWorksAsExpected()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->seed('mockenv', 'UserSeeder');
        rewind($this->manager->getOutput()->getStream());
        $output = stream_get_contents($this->manager->getOutput()->getStream());
        $this->assertStringContainsString('UserSeeder', $output);
    }

    public function testExecuteANonExistentSeedWorksAsExpected()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $this->manager->setEnvironments(['mockenv' => $envStub]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The seed class "NonExistentSeeder" does not exist');

        $this->manager->seed('mockenv', 'NonExistentSeeder');
    }

    public function testOrderSeeds()
    {
        $seeds = array_values($this->manager->getSeeds('mockenv'));
        $this->assertInstanceOf('UserSeeder', $seeds[0]);
        $this->assertInstanceOf('GSeeder', $seeds[1]);
        $this->assertInstanceOf('PostSeeder', $seeds[2]);
    }

    public function testSeedWillNotBeExecuted()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->seed('mockenv', 'UserSeederNotExecuted');
        rewind($this->manager->getOutput()->getStream());
        $output = stream_get_contents($this->manager->getOutput()->getStream());
        $this->assertStringContainsString('skipped', $output);
    }

    public function testGettingInputObject()
    {
        $migrations = $this->manager->getMigrations('mockenv');
        $seeds = $this->manager->getSeeds('mockenv');
        $inputObject = $this->manager->getInput();
        $this->assertInstanceOf('\Symfony\Component\Console\Input\InputInterface', $inputObject);

        foreach ($migrations as $migration) {
            $this->assertEquals($inputObject, $migration->getInput());
        }
        foreach ($seeds as $seed) {
            $this->assertEquals($inputObject, $seed->getInput());
        }
    }

    public function testGettingOutputObject()
    {
        $migrations = $this->manager->getMigrations('mockenv');
        $seeds = $this->manager->getSeeds('mockenv');
        $outputObject = $this->manager->getOutput();
        $this->assertInstanceOf('\Symfony\Component\Console\Output\OutputInterface', $outputObject);

        foreach ($migrations as $migration) {
            $this->assertEquals($outputObject, $migration->getOutput());
        }
        foreach ($seeds as $seed) {
            $this->assertEquals($outputObject, $seed->getOutput());
        }
    }

    public function testGettingAnInvalidEnvironment()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The environment "invalidenv" does not exist');

        $this->manager->getEnvironment('invalidenv');
    }

    public function testReversibleMigrationsWorkAsExpected()
    {
        $adapter = $this->prepareEnvironment([
            'migrations' => ROOT . '/config/Reversiblemigrations',
        ]);

        // migrate to the latest version
        $this->manager->migrate('production');

        // ensure up migrations worked
        $this->assertFalse($adapter->hasTable('info'));
        $this->assertTrue($adapter->hasTable('statuses'));
        $this->assertTrue($adapter->hasTable('users'));
        $this->assertTrue($adapter->hasTable('just_logins'));
        $this->assertFalse($adapter->hasTable('user_logins'));
        $this->assertTrue($adapter->hasColumn('users', 'biography'));
        $this->assertTrue($adapter->hasForeignKey('just_logins', ['user_id']));
        $this->assertTrue($adapter->hasTable('change_direction_test'));
        $this->assertTrue($adapter->hasColumn('change_direction_test', 'subthing'));
        $this->assertEquals(
            2,
            count($adapter->fetchAll('SELECT * FROM change_direction_test WHERE subthing IS NOT NULL'))
        );

        // revert all changes to the first
        $this->manager->rollback('production', '20121213232502');

        // ensure reversed migrations worked
        $this->assertTrue($adapter->hasTable('info'));
        $this->assertFalse($adapter->hasTable('statuses'));
        $this->assertFalse($adapter->hasTable('user_logins'));
        $this->assertFalse($adapter->hasTable('just_logins'));
        $this->assertTrue($adapter->hasColumn('users', 'bio'));
        $this->assertFalse($adapter->hasForeignKey('user_logins', ['user_id']));
        $this->assertFalse($adapter->hasTable('change_direction_test'));

        // revert all changes
        $this->manager->rollback('production', '0');

        $this->assertFalse($adapter->hasTable('info'));
        $this->assertFalse($adapter->hasTable('users'));
    }

    public function testReversibleMigrationWithIndexConflict()
    {
        if ($this->getDriverType() !== 'mysql') {
            $this->markTestSkipped('Test requires mysql connection');
        }
        $configArray = $this->getConfigArray();
        $adapter = $this->manager->getEnvironment('production')->getAdapter();

        // override the migrations directory to use the reversible migrations
        $configArray['paths']['migrations'] = ROOT . '/config/DropIndexRegression/';
        $config = new Config($configArray);

        // ensure the database is empty
        $dbName = ConnectionManager::getConfig('test')['database'] ?? null;
        $this->assertNotEmpty($dbName);
        $adapter->dropDatabase($dbName);
        $adapter->createDatabase($dbName);
        $adapter->disconnect();

        // migrate to the latest version
        $this->manager->setConfig($config);
        $this->manager->migrate('production');

        // ensure up migrations worked
        $this->assertTrue($adapter->hasTable('my_table'));
        $this->assertTrue($adapter->hasTable('my_other_table'));
        $this->assertTrue($adapter->hasColumn('my_table', 'entity_id'));
        $this->assertTrue($adapter->hasForeignKey('my_table', ['entity_id']));

        // revert all changes to the first
        $this->manager->rollback('production', '20121213232502');

        // ensure reversed migrations worked
        $this->assertTrue($adapter->hasTable('my_table'));
        $this->assertTrue($adapter->hasTable('my_other_table'));
        $this->assertTrue($adapter->hasColumn('my_table', 'entity_id'));
        $this->assertFalse($adapter->hasForeignKey('my_table', ['entity_id']));
        $this->assertFalse($adapter->hasIndex('my_table', ['entity_id']));
    }

    public function testReversibleMigrationWithFKConflictOnTableDrop()
    {
        if ($this->getDriverType() !== 'mysql') {
            $this->markTestSkipped('Test requires mysql');
        }
        $configArray = $this->getConfigArray();
        $adapter = $this->manager->getEnvironment('production')->getAdapter();

        // override the migrations directory to use the reversible migrations
        $configArray['paths']['migrations'] = ROOT . '/config/DropTableWithFkRegression';
        $config = new Config($configArray);

        // ensure the database is empty
        $dbName = ConnectionManager::getConfig('test')['database'] ?? null;
        $this->assertNotEmpty($dbName);
        $adapter->dropDatabase($dbName);
        $adapter->createDatabase($dbName);
        $adapter->disconnect();

        // migrate to the latest version
        $this->manager->setConfig($config);
        $this->manager->migrate('production');

        // ensure up migrations worked
        $this->assertTrue($adapter->hasTable('orders'));
        $this->assertTrue($adapter->hasTable('customers'));
        $this->assertTrue($adapter->hasColumn('orders', 'order_date'));
        $this->assertTrue($adapter->hasColumn('orders', 'customer_id'));
        $this->assertTrue($adapter->hasForeignKey('orders', ['customer_id']));

        // revert all changes to the first
        $this->manager->rollback('production', '20190928205056');

        // ensure reversed migrations worked
        $this->assertTrue($adapter->hasTable('orders'));
        $this->assertTrue($adapter->hasColumn('orders', 'order_date'));
        $this->assertFalse($adapter->hasColumn('orders', 'customer_id'));
        $this->assertFalse($adapter->hasTable('customers'));
        $this->assertFalse($adapter->hasForeignKey('orders', ['customer_id']));

        $this->manager->rollback('production');
        $this->assertFalse($adapter->hasTable('orders'));
        $this->assertFalse($adapter->hasTable('customers'));
    }

    public function testBreakpointsTogglingOperateAsExpected()
    {
        if ($this->getDriverType() !== 'mysql') {
            $this->markTestSkipped('Test requires mysql');
        }
        $configArray = $this->getConfigArray();
        $adapter = $this->manager->getEnvironment('production')->getAdapter();

        $config = new Config($configArray);

        // ensure the database is empty
        $dbName = ConnectionManager::getConfig('test')['database'] ?? null;
        $this->assertNotEmpty($dbName);
        $adapter->dropDatabase($dbName);
        $adapter->createDatabase($dbName);
        $adapter->disconnect();

        // migrate to the latest version
        $this->manager->setConfig($config);
        $this->manager->migrate('production');

        // Get the versions
        $originalVersions = $this->manager->getEnvironment('production')->getVersionLog();
        $this->assertEquals(0, reset($originalVersions)['breakpoint']);
        $this->assertEquals(0, end($originalVersions)['breakpoint']);

        // Wait until the second has changed.
        sleep(1);

        // Toggle the breakpoint on most recent migration
        $this->manager->toggleBreakpoint('production', null);

        // ensure breakpoint is set
        $firstToggle = $this->manager->getEnvironment('production')->getVersionLog();
        $this->assertEquals(0, reset($firstToggle)['breakpoint']);
        $this->assertEquals(1, end($firstToggle)['breakpoint']);

        // ensure no other data has changed.
        foreach ($originalVersions as $originalVersionKey => $originalVersion) {
            foreach ($originalVersion as $column => $value) {
                if (!is_numeric($column) && $column !== 'breakpoint') {
                    $this->assertEquals($value, $firstToggle[$originalVersionKey][$column]);
                }
            }
        }

        // Wait until the second has changed.
        sleep(1);

        // Toggle the breakpoint on most recent migration
        $this->manager->toggleBreakpoint('production', null);

        // ensure breakpoint is set
        $secondToggle = $this->manager->getEnvironment('production')->getVersionLog();
        $this->assertEquals(0, reset($secondToggle)['breakpoint']);
        $this->assertEquals(0, end($secondToggle)['breakpoint']);

        // ensure no other data has changed.
        foreach ($originalVersions as $originalVersionKey => $originalVersion) {
            foreach ($originalVersion as $column => $value) {
                if (!is_numeric($column) && $column !== 'breakpoint') {
                    $this->assertEquals($value, $secondToggle[$originalVersionKey][$column]);
                }
            }
        }

        // Wait until the second has changed.
        sleep(1);

        // Reset all breakpoints and toggle the most recent migration twice
        $this->manager->removeBreakpoints('production');
        $this->manager->toggleBreakpoint('production', null);
        $this->manager->toggleBreakpoint('production', null);

        // ensure breakpoint is not set
        $resetVersions = $this->manager->getEnvironment('production')->getVersionLog();
        $this->assertEquals(0, reset($resetVersions)['breakpoint']);
        $this->assertEquals(0, end($resetVersions)['breakpoint']);

        // ensure no other data has changed.
        foreach ($originalVersions as $originalVersionKey => $originalVersion) {
            foreach ($originalVersion as $column => $value) {
                if (!is_numeric($column)) {
                    $this->assertEquals($value, $resetVersions[$originalVersionKey][$column]);
                }
            }
        }

        // Wait until the second has changed.
        sleep(1);

        // Set the breakpoint on the latest migration
        $this->manager->setBreakpoint('production', null);

        // ensure breakpoint is set
        $setLastVersions = $this->manager->getEnvironment('production')->getVersionLog();
        $this->assertEquals(0, reset($setLastVersions)['breakpoint']);
        $this->assertEquals(1, end($setLastVersions)['breakpoint']);

        // ensure no other data has changed.
        foreach ($originalVersions as $originalVersionKey => $originalVersion) {
            foreach ($originalVersion as $column => $value) {
                if (!is_numeric($column) && $column !== 'breakpoint') {
                    $this->assertEquals($value, $setLastVersions[$originalVersionKey][$column]);
                }
            }
        }

        // Wait until the second has changed.
        sleep(1);

        // Set the breakpoint on the first migration
        $this->manager->setBreakpoint('production', reset($originalVersions)['version']);

        // ensure breakpoint is set
        $setFirstVersion = $this->manager->getEnvironment('production')->getVersionLog();
        $this->assertEquals(1, reset($setFirstVersion)['breakpoint']);
        $this->assertEquals(1, end($setFirstVersion)['breakpoint']);

        // ensure no other data has changed.
        foreach ($originalVersions as $originalVersionKey => $originalVersion) {
            foreach ($originalVersion as $column => $value) {
                if (!is_numeric($column) && $column !== 'breakpoint') {
                    $this->assertEquals($value, $resetVersions[$originalVersionKey][$column]);
                }
            }
        }

        // Wait until the second has changed.
        sleep(1);

        // Unset the breakpoint on the latest migration
        $this->manager->unsetBreakpoint('production', null);

        // ensure breakpoint is set
        $unsetLastVersions = $this->manager->getEnvironment('production')->getVersionLog();
        $this->assertEquals(1, reset($unsetLastVersions)['breakpoint']);
        $this->assertEquals(0, end($unsetLastVersions)['breakpoint']);

        // ensure no other data has changed.
        foreach ($originalVersions as $originalVersionKey => $originalVersion) {
            foreach ($originalVersion as $column => $value) {
                if (!is_numeric($column) && $column !== 'breakpoint') {
                    $this->assertEquals($value, $unsetLastVersions[$originalVersionKey][$column]);
                }
            }
        }

        // Wait until the second has changed.
        sleep(1);

        // Unset the breakpoint on the first migration
        $this->manager->unsetBreakpoint('production', reset($originalVersions)['version']);

        // ensure breakpoint is set
        $unsetFirstVersion = $this->manager->getEnvironment('production')->getVersionLog();
        $this->assertEquals(0, reset($unsetFirstVersion)['breakpoint']);
        $this->assertEquals(0, end($unsetFirstVersion)['breakpoint']);

        // ensure no other data has changed.
        foreach ($originalVersions as $originalVersionKey => $originalVersion) {
            foreach ($originalVersion as $column => $value) {
                if (!is_numeric($column)) {
                    $this->assertEquals($value, $unsetFirstVersion[$originalVersionKey][$column]);
                }
            }
        }
    }

    public function testBreakpointWithInvalidVersion()
    {
        if ($this->getDriverType() !== 'mysql') {
            $this->markTestSkipped('test requires mysql');
        }
        $configArray = $this->getConfigArray();
        $adapter = $this->manager->getEnvironment('production')->getAdapter();

        $config = new Config($configArray);

        // ensure the database is empty
        $dbName = ConnectionManager::getConfig('test')['database'] ?? null;
        $this->assertNotEmpty($dbName);
        $adapter->dropDatabase($dbName);
        $adapter->createDatabase($dbName);
        $adapter->disconnect();

        // migrate to the latest version
        $this->manager->setConfig($config);
        $this->manager->migrate('production');
        $this->manager->getOutput()->setDecorated(false);

        // set breakpoint on most recent migration
        $this->manager->toggleBreakpoint('production', 999);

        rewind($this->manager->getOutput()->getStream());
        $output = stream_get_contents($this->manager->getOutput()->getStream());

        $this->assertStringContainsString('is not a valid version', $output);
    }

    public function testPostgresFullMigration()
    {
        if ($this->getDriverType() !== 'postgres') {
            $this->markTestSkipped('Test requires postgres');
        }

        $adapter = $this->prepareEnvironment([
            'migrations' => ROOT . '/config/Postgres',
        ]);
        // migrate to the latest version
        $this->manager->migrate('production');

        $this->assertTrue($adapter->hasTable('articles'));
        $this->assertTrue($adapter->hasTable('categories'));
        $this->assertTrue($adapter->hasTable('composite_pks'));
        $this->assertTrue($adapter->hasTable('orders'));
        $this->assertTrue($adapter->hasTable('products'));
        $this->assertTrue($adapter->hasTable('special_pks'));
        $this->assertTrue($adapter->hasTable('special_tags'));
        $this->assertTrue($adapter->hasTable('users'));

        $this->manager->rollback('production', 'all');

        $this->assertFalse($adapter->hasTable('articles'));
        $this->assertFalse($adapter->hasTable('categories'));
        $this->assertFalse($adapter->hasTable('composite_pks'));
        $this->assertFalse($adapter->hasTable('orders'));
        $this->assertFalse($adapter->hasTable('products'));
        $this->assertFalse($adapter->hasTable('special_pks'));
        $this->assertFalse($adapter->hasTable('special_tags'));
        $this->assertFalse($adapter->hasTable('users'));
    }

    public function testMigrationWithDropColumnAndForeignKeyAndIndex()
    {
        if ($this->getDriverType() !== 'mysql') {
            $this->markTestSkipped('Test requires mysql');
        }
        $configArray = $this->getConfigArray();
        $adapter = $this->manager->getEnvironment('production')->getAdapter();

        // override the migrations directory to use the reversible migrations
        $configArray['paths']['migrations'] = ROOT . '/config/DropColumnFkIndexRegression';
        $config = new Config($configArray);

        // ensure the database is empty
        $dbName = ConnectionManager::getConfig('test')['database'] ?? null;
        $this->assertNotEmpty($dbName);
        $adapter->dropDatabase($dbName);
        $adapter->createDatabase($dbName);
        $adapter->disconnect();

        $this->manager->setConfig($config);
        $this->manager->migrate('production', 20190928205056);

        $this->assertTrue($adapter->hasTable('table1'));
        $this->assertTrue($adapter->hasTable('table2'));
        $this->assertTrue($adapter->hasTable('table3'));
        $this->assertTrue($adapter->hasColumn('table1', 'table2_id'));
        $this->assertTrue($adapter->hasForeignKey('table1', ['table2_id'], 'table1_table2_id'));
        $this->assertTrue($adapter->hasIndexByName('table1', 'table1_table2_id'));
        $this->assertTrue($adapter->hasColumn('table1', 'table3_id'));
        $this->assertTrue($adapter->hasForeignKey('table1', ['table3_id'], 'table1_table3_id'));
        $this->assertTrue($adapter->hasIndexByName('table1', 'table1_table3_id'));

        // Run the next migration
        $this->manager->migrate('production');
        $this->assertTrue($adapter->hasTable('table1'));
        $this->assertTrue($adapter->hasTable('table2'));
        $this->assertTrue($adapter->hasTable('table3'));
        $this->assertTrue($adapter->hasColumn('table1', 'table2_id'));
        $this->assertTrue($adapter->hasForeignKey('table1', ['table2_id'], 'table1_table2_id'));
        $this->assertTrue($adapter->hasIndexByName('table1', 'table1_table2_id'));
        $this->assertFalse($adapter->hasColumn('table1', 'table3_id'));
        $this->assertFalse($adapter->hasForeignKey('table1', ['table3_id'], 'table1_table3_id'));
        $this->assertFalse($adapter->hasIndexByName('table1', 'table1_table3_id'));

        // rollback
        $this->manager->rollback('production');
        $this->manager->rollback('production');

        // ensure reversed migrations worked
        $this->assertTrue($adapter->hasTable('table1'));
        $this->assertTrue($adapter->hasTable('table2'));
        $this->assertTrue($adapter->hasTable('table3'));
        $this->assertFalse($adapter->hasColumn('table1', 'table2_id'));
        $this->assertFalse($adapter->hasForeignKey('table1', ['table2_id'], 'table1_table2_id'));
        $this->assertFalse($adapter->hasIndexByName('table1', 'table1_table2_id'));
        $this->assertFalse($adapter->hasColumn('table1', 'table3_id'));
        $this->assertFalse($adapter->hasForeignKey('table1', ['table3_id'], 'table1_table3_id'));
        $this->assertFalse($adapter->hasIndexByName('table1', 'table1_table3_id'));
    }

    public function testInvalidVersionBreakpoint()
    {
        // stub environment
        $envStub = $this->getMockBuilder(Environment::class)
            ->setConstructorArgs(['mockenv', []])
            ->getMock();
        $envStub->expects($this->once())
                ->method('getVersionLog')
                ->will($this->returnValue(
                    [
                        '20120111235330' =>
                            [
                                'version' => '20120111235330',
                                'start_time' => '2012-01-11 23:53:36',
                                'end_time' => '2012-01-11 23:53:37',
                                'migration_name' => '',
                                'breakpoint' => '0',
                            ],
                    ]
                ));

        $this->manager->setEnvironments(['mockenv' => $envStub]);
        $this->manager->getOutput()->setDecorated(false);
        $this->manager->setBreakpoint('mockenv', 20120133235330);

        rewind($this->manager->getOutput()->getStream());
        $outputStr = stream_get_contents($this->manager->getOutput()->getStream());
        $this->assertEquals('warning 20120133235330 is not a valid version', trim($outputStr));
    }

    public function testMigrationWillNotBeExecuted()
    {
        if ($this->getDriverType() !== 'mysql') {
            $this->markTestSkipped('Test requires mysql');
        }
        $configArray = $this->getConfigArray();
        $adapter = $this->manager->getEnvironment('production')->getAdapter();

        // override the migrations directory to use the should execute migrations
        $configArray['paths']['migrations'] = ROOT . '/config/ShouldExecute/';
        $config = new Config($configArray);

        // ensure the database is empty
        $dbName = ConnectionManager::getConfig('test')['database'] ?? null;
        $this->assertNotEmpty($dbName);
        $adapter->dropDatabase($dbName);
        $adapter->createDatabase($dbName);
        $adapter->disconnect();

        // Run the migration with shouldExecute returning false: the table should not be created
        $this->manager->setConfig($config);
        $this->manager->migrate('production', 20201207205056);

        $this->assertFalse($adapter->hasTable('info'));

        // Run the migration with shouldExecute returning true: the table should be created
        $this->manager->migrate('production', 20201207205057);

        $this->assertTrue($adapter->hasTable('info'));
    }
}
