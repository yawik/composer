<?php

/*
 * This file is part of the Yawik project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace YawikTest\Composer;

use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Filesystem\Filesystem;
use Yawik\Composer\Event\ConfigureEvent;
use Yawik\Composer\PermissionsFixer;
use PHPUnit\Framework\TestCase;
use Yawik\Composer\RequireDirectoryPermissionInterface;
use Yawik\Composer\RequireFilePermissionInterface;
use Core\Options\ModuleOptions as CoreOptions;

/**
 * Class PermissionsFixerTest
 *
 * @package     YawikTest\Composer
 * @author      Anthonius Munthi <http://itstoni.com>
 * @author      Mathias Gelhausen <gelhausen@cross-solution.de>
 * @since       0.32.0
 * @since       3.0
 *              Upgrade to phpunit 8
 * @covers      \Yawik\Composer\PermissionsFixer
 */
class PermissionsFixerTest extends TestCase
{
    /**
     * @var PermissionsFixer
     */
    private $target;

    private $output;

    public function setUp(): void
    {
        $output   = new StreamOutput(fopen('php://memory', 'w'));
        $input    = new StringInput('some input');

        // setup the target
        $target = new PermissionsFixer();
        $target->setOutput($output);
        $target->setInput($input);

        $this->output       = $output;
        $this->target       = $target;
    }

    public function testOnConfigureEvent()
    {
        $module1    = $this->createMock(RequireFileAndDirPermissionModule::class);
        $module2    = $this->createMock(RequireFileAndDirPermissionModule::class);
        $modError   = $this->createMock(RequireFileAndDirPermissionModule::class);
        $modules    = [$module1,$module2];
        $plugin     = $this->getMockBuilder(PermissionsFixer::class)
            ->setMethods(['touch','chmod','mkdir','logError'])
            ->getMock()
        ;

        foreach ($modules as $index => $module) {
            $index = $index+1;
            $module->expects($this->once())
                ->method('getRequiredDirectoryLists')
                ->willReturn([
                    'public/static/module'.$index
                ])
            ;
            $module->expects($this->once())
                ->method('getRequiredFileLists')
                ->willReturn([
                    'public/module'.$index.'.log'
                ])
            ;
        }

        $modError->expects($this->once())
            ->method('getRequiredDirectoryLists')
            ->willReturn('foo')
        ;
        $modError->expects($this->once())
            ->method('getRequiredFileLists')
            ->willReturn('bar')
        ;
        $modules[] = $modError;
        $options = $this->prophesize(CoreOptions::class);
        $event = $this->prophesize(ConfigureEvent::class);
        $event->getModules()
            ->willReturn($modules)
            ->shouldBeCalled()
        ;
        $event->getOptions()
            ->willReturn($options)
            ->shouldBeCalled()
        ;

        $plugin->expects($this->exactly(2))
            ->method('touch')
            ->withConsecutive(
                ['public/module1.log'],
                ['public/module2.log']
            )
        ;
        $plugin->expects($this->exactly(2))
            ->method('mkdir')
            ->withConsecutive(
                ['public/static/module1'],
                ['public/static/module2']
            );
        $plugin->expects($this->exactly(4))
            ->method('chmod')
            ->withConsecutive(
                ['public/static/module1'],
                ['public/static/module2'],
                ['public/module1.log'],
                ['public/module2.log']
            )
        ;

        $plugin->expects($this->exactly(2))
            ->method('logError')
            ->withConsecutive(
                [$this->stringContains('should return an array.')],
                [$this->stringContains('should return an array.')]
            )
        ;

        $plugin->onConfigureEvent($event->reveal());
    }

    public function testFixThrowErrorOnInvalidDirectory()
    {
        $modDir         = $this->createMock(RequireDirectoryPermissionInterface::class);
        $modFile        = $this->createMock(RequireFilePermissionInterface::class);
        $options        = $this->prophesize(CoreOptions::class);
        $dirException   = 'some dir exception';
        $fileException  = 'some file exception';

        $plugin     = $this->getMockBuilder(PermissionsFixer::class)
            ->setMethods(['touch','chmod','mkdir','logError'])
            ->getMock()
        ;

        $modDir->expects($this->once())
            ->method('getRequiredDirectoryLists')
            ->willReturn(['some_dir']);
        $modFile->expects($this->once())
            ->method('getRequiredFileLists')
            ->willReturn(['some_file']);

        $plugin->expects($this->once())
            ->method('mkdir')
            ->with('some_dir')
            ->willThrowException(new \Exception($dirException));

        $plugin->expects($this->once())
            ->method('touch')
            ->with('some_file')
            ->willThrowException(new \Exception($fileException));
        $plugin->expects($this->exactly(2))
            ->method('logError')
            ->withConsecutive(
                [$dirException],
                [$fileException]
            );

        $plugin->fix($options->reveal(), [$modDir, $modFile]);
    }

    /**
     * @param string    $method
     * @param array     $args
     * @param string    $expectLog
     * @param string    $logType
     * @dataProvider    getTestFilesystemAction
     */
    public function testFilesystemAction($method, $args, $expectLog, $logType='log')
    {
        $plugin = $this->getMockBuilder(TestPermissionFixer::class)
            ->setMethods([$logType])
            ->getMock()
        ;

        $fs = $this->createMock(Filesystem::class);
        if ('log' == $logType) {
            $fs->expects($this->once())
                ->method($method)
            ;
        } else {
            $fs->expects($this->once())
                ->method($method)
                ->willThrowException(new \Exception($expectLog))
            ;
        }

        $plugin->expects($this->once())
            ->method($logType)
            ->with($this->stringContains($expectLog), $method)
        ;

        $plugin->setFilesystem($fs);
        call_user_func_array([$plugin,$method], $args);
    }

    public function getTestFilesystemAction()
    {
        return [
            ['mkdir',['some/dir'],'some/dir'],
            ['mkdir',['some/dir'],'some error','logError'],

            ['chmod',['some/dir'],'some/dir'],
            ['chmod',['some/file',0775],'some error','logError'],

            ['touch',['some/file'],'some/file'],
            ['touch',['some/file'],'some error','logError'],
        ];
    }
}

abstract class RequireFileAndDirPermissionModule implements
    RequireDirectoryPermissionInterface,
    RequireFilePermissionInterface
{
}
