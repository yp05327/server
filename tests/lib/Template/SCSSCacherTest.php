<?php
/**
 * @copyright Copyright (c) 2017 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace Test\Template;

use OC\Files\AppData\Factory;
use OC\Template\SCSSCacher;
use OCA\Theming\ThemingDefaults;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ICache;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IURLGenerator;

class SCSSCacherTest extends \Test\TestCase {
	/** @var ILogger|\PHPUnit_Framework_MockObject_MockObject */
	protected $logger;
	/** @var IAppData|\PHPUnit_Framework_MockObject_MockObject */
	protected $appData;
	/** @var IURLGenerator|\PHPUnit_Framework_MockObject_MockObject */
	protected $urlGenerator;
	/** @var IConfig|\PHPUnit_Framework_MockObject_MockObject */
	protected $config;
	/** @var ThemingDefaults|\PHPUnit_Framework_MockObject_MockObject */
	protected $themingDefaults;
	/** @var SCSSCacher */
	protected $scssCacher;
	/** @var ICache|\PHPUnit_Framework_MockObject_MockObject */
	protected $depsCache;

	protected function setUp() {
		parent::setUp();
		$this->logger = $this->createMock(ILogger::class);
		$this->appData = $this->createMock(IAppData::class);
		/** @var Factory|\PHPUnit_Framework_MockObject_MockObject $factory */
		$factory = $this->createMock(Factory::class);
		$factory->method('get')->with('css')->willReturn($this->appData);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->config = $this->createMock(IConfig::class);
		$this->depsCache = $this->createMock(ICache::class);
		$this->themingDefaults = $this->createMock(ThemingDefaults::class);
		$this->scssCacher = new SCSSCacher(
			$this->logger,
			$factory,
			$this->urlGenerator,
			$this->config,
			$this->themingDefaults,
			\OC::$SERVERROOT,
			$this->depsCache
		);
		$this->themingDefaults->expects($this->any())->method('getScssVariables')->willReturn([]);
	}

	public function testProcessUncachedFileNoAppDataFolder() {
		$folder = $this->createMock(ISimpleFolder::class);
		$file = $this->createMock(ISimpleFile::class);
		$file->expects($this->any())->method('getSize')->willReturn(1);

		$this->appData->expects($this->once())->method('getFolder')->with('core')->willThrowException(new NotFoundException());
		$this->appData->expects($this->once())->method('newFolder')->with('core')->willReturn($folder);

		$fileDeps = $this->createMock(ISimpleFile::class);
		$gzfile = $this->createMock(ISimpleFile::class);

		$folder->method('getFile')
			->will($this->returnCallback(function($path) use ($file, $gzfile) {
				if ($path === 'styles.css') {
					return $file;
				} else if ($path === 'styles.css.deps') {
					throw new NotFoundException();
				} else if ($path === 'styles.css.gzip') {
					return $gzfile;
				} else {
					$this->fail();
				}
			}));
		$folder->expects($this->once())
			->method('newFile')
			->with('styles.css.deps')
			->willReturn($fileDeps);

		$actual = $this->scssCacher->process(\OC::$SERVERROOT, '/core/css/styles.scss', 'core');
		$this->assertTrue($actual);
	}

	public function testProcessUncachedFile() {
		$folder = $this->createMock(ISimpleFolder::class);
		$this->appData->expects($this->once())->method('getFolder')->with('core')->willReturn($folder);
		$file = $this->createMock(ISimpleFile::class);
		$file->expects($this->any())->method('getSize')->willReturn(1);
		$fileDeps = $this->createMock(ISimpleFile::class);
		$gzfile = $this->createMock(ISimpleFile::class);

		$folder->method('getFile')
			->will($this->returnCallback(function($path) use ($file, $gzfile) {
				if ($path === 'styles.css') {
					return $file;
				} else if ($path === 'styles.css.deps') {
					throw new NotFoundException();
				} else if ($path === 'styles.css.gzip') {
					return $gzfile;
				}else {
					$this->fail();
				}
			}));
		$folder->expects($this->once())
			->method('newFile')
			->with('styles.css.deps')
			->willReturn($fileDeps);

		$actual = $this->scssCacher->process(\OC::$SERVERROOT, '/core/css/styles.scss', 'core');
		$this->assertTrue($actual);
	}

	public function testProcessCachedFile() {
		$folder = $this->createMock(ISimpleFolder::class);
		$this->appData->expects($this->once())->method('getFolder')->with('core')->willReturn($folder);
		$file = $this->createMock(ISimpleFile::class);
		$fileDeps = $this->createMock(ISimpleFile::class);
		$fileDeps->expects($this->any())->method('getSize')->willReturn(1);
		$gzFile = $this->createMock(ISimpleFile::class);

		$folder->method('getFile')
			->will($this->returnCallback(function($name) use ($file, $fileDeps, $gzFile) {
				if ($name === 'styles.css') {
					return $file;
				} else if ($name === 'styles.css.deps') {
					return $fileDeps;
				} else if ($name === 'styles.css.gzip') {
					return $gzFile;
				}
				$this->fail();
			}));

		$actual = $this->scssCacher->process(\OC::$SERVERROOT, '/core/css/styles.scss', 'core');
		$this->assertTrue($actual);
	}

	public function testProcessCachedFileMemcache() {
		$folder = $this->createMock(ISimpleFolder::class);
		$this->appData->expects($this->once())
			->method('getFolder')
			->with('core')
			->willReturn($folder);
		$folder->method('getName')
			->willReturn('core');

		$file = $this->createMock(ISimpleFile::class);

		$fileDeps = $this->createMock(ISimpleFile::class);
		$fileDeps->expects($this->any())->method('getSize')->willReturn(1);

		$gzFile = $this->createMock(ISimpleFile::class);

		$folder->method('getFile')
			->will($this->returnCallback(function($name) use ($file, $fileDeps, $gzFile) {
				if ($name === 'styles.css') {
					return $file;
				} else if ($name === 'styles.css.deps') {
					return $fileDeps;
				} else if ($name === 'styles.css.gzip') {
					return $gzFile;
				}
				$this->fail();
			}));

		$actual = $this->scssCacher->process(\OC::$SERVERROOT, '/core/css/styles.scss', 'core');
		$this->assertTrue($actual);
	}

	public function testIsCachedNoFile() {
		$fileNameCSS = "styles.css";
		$folder = $this->createMock(ISimpleFolder::class);

		$folder->expects($this->at(0))->method('getFile')->with($fileNameCSS)->willThrowException(new NotFoundException());
		$actual = self::invokePrivate($this->scssCacher, 'isCached', [$fileNameCSS, $folder]);
		$this->assertFalse($actual);
	}

	public function testIsCachedNoDepsFile() {
		$fileNameCSS = "styles.css";
		$folder = $this->createMock(ISimpleFolder::class);
		$file = $this->createMock(ISimpleFile::class);

		$file->expects($this->once())->method('getSize')->willReturn(1);
		$folder->method('getFile')
			->will($this->returnCallback(function($path) use ($file) {
				if ($path === 'styles.css') {
					return $file;
				} else if ($path === 'styles.css.deps') {
					throw new NotFoundException();
				} else {
					$this->fail();
				}
			}));

		$actual = self::invokePrivate($this->scssCacher, 'isCached', [$fileNameCSS, $folder]);
		$this->assertFalse($actual);
	}
	public function testCacheNoFile() {
		$fileNameCSS = "styles.css";
		$fileNameSCSS = "styles.scss";
		$folder = $this->createMock(ISimpleFolder::class);
		$file = $this->createMock(ISimpleFile::class);
		$depsFile = $this->createMock(ISimpleFile::class);
		$gzipFile = $this->createMock(ISimpleFile::class);

		$webDir = "core/css";
		$path = \OC::$SERVERROOT . '/core/css/';

		$folder->method('getFile')->willThrowException(new NotFoundException());
		$folder->method('newFile')->will($this->returnCallback(function($fileName) use ($file, $depsFile, $gzipFile) {
			if ($fileName === 'styles.css') {
				return $file;
			} else if ($fileName === 'styles.css.deps') {
				return $depsFile;
			} else if ($fileName === 'styles.css.gzip') {
				return $gzipFile;
			}
			throw new \Exception();
		}));

		$file->expects($this->once())->method('putContent');
		$depsFile->expects($this->once())->method('putContent');
		$gzipFile->expects($this->once())->method('putContent');

		$actual = self::invokePrivate($this->scssCacher, 'cache', [$path, $fileNameCSS, $fileNameSCSS, $folder, $webDir]);
		$this->assertTrue($actual);
	}

	public function testCache() {
		$fileNameCSS = "styles.css";
		$fileNameSCSS = "styles.scss";
		$folder = $this->createMock(ISimpleFolder::class);
		$file = $this->createMock(ISimpleFile::class);
		$depsFile = $this->createMock(ISimpleFile::class);
		$gzipFile = $this->createMock(ISimpleFile::class);

		$webDir = "core/css";
		$path = \OC::$SERVERROOT;

		$folder->method('getFile')->will($this->returnCallback(function($fileName) use ($file, $depsFile, $gzipFile) {
			if ($fileName === 'styles.css') {
				return $file;
			} else if ($fileName === 'styles.css.deps') {
				return $depsFile;
			} else if ($fileName === 'styles.css.gzip') {
				return $gzipFile;
			}
			throw new \Exception();
		}));

		$file->expects($this->once())->method('putContent');
		$depsFile->expects($this->once())->method('putContent');
		$gzipFile->expects($this->once())->method('putContent');

		$actual = self::invokePrivate($this->scssCacher, 'cache', [$path, $fileNameCSS, $fileNameSCSS, $folder, $webDir]);
		$this->assertTrue($actual);
	}

	public function testCacheSuccess() {
		$fileNameCSS = "styles-success.css";
		$fileNameSCSS = "../../tests/data/scss/styles-success.scss";
		$folder = $this->createMock(ISimpleFolder::class);
		$file = $this->createMock(ISimpleFile::class);
		$depsFile = $this->createMock(ISimpleFile::class);
		$gzipFile = $this->createMock(ISimpleFile::class);

		$webDir = "tests/data/scss";
		$path = \OC::$SERVERROOT . $webDir;

		$folder->method('getFile')->will($this->returnCallback(function($fileName) use ($file, $depsFile, $gzipFile) {
			if ($fileName === 'styles-success.css') {
				return $file;
			} else if ($fileName === 'styles-success.css.deps') {
				return $depsFile;
			} else if ($fileName === 'styles-success.css.gzip') {
				return $gzipFile;
			}
			throw new \Exception();
		}));

		$file->expects($this->at(0))->method('putContent')->with($this->callback(
			function ($content){
				return 'body{background-color:#0082c9}' === $content;
			}));
		$depsFile->expects($this->at(0))->method('putContent')->with($this->callback(
			function ($content) {
				$deps = json_decode($content, true);
				return array_key_exists(\OC::$SERVERROOT . '/core/css/variables.scss', $deps)
					&& array_key_exists(\OC::$SERVERROOT . '/tests/data/scss/styles-success.scss', $deps);
			}));
		$gzipFile->expects($this->at(0))->method('putContent')->with($this->callback(
			function ($content) {
				return gzdecode($content) === 'body{background-color:#0082c9}';
			}
		));

		$actual = self::invokePrivate($this->scssCacher, 'cache', [$path, $fileNameCSS, $fileNameSCSS, $folder, $webDir]);
		$this->assertTrue($actual);
	}

	public function testCacheFailure() {
		$fileNameCSS = "styles-error.css";
		$fileNameSCSS = "../../tests/data/scss/styles-error.scss";
		$folder = $this->createMock(ISimpleFolder::class);
		$file = $this->createMock(ISimpleFile::class);
		$depsFile = $this->createMock(ISimpleFile::class);

		$webDir = "/tests/data/scss";
		$path = \OC::$SERVERROOT . $webDir;

		$folder->expects($this->at(0))->method('getFile')->with($fileNameCSS)->willReturn($file);
		$folder->expects($this->at(1))->method('getFile')->with($fileNameCSS . '.deps')->willReturn($depsFile);

		$actual = self::invokePrivate($this->scssCacher, 'cache', [$path, $fileNameCSS, $fileNameSCSS, $folder, $webDir]);
		$this->assertFalse($actual);
	}

	public function testRebaseUrls() {
		$webDir = 'apps/files/css';
		$css = '#id { background-image: url(\'../img/image.jpg\'); }';
		$actual = self::invokePrivate($this->scssCacher, 'rebaseUrls', [$css, $webDir]);
		$expected = '#id { background-image: url(\'../../../apps/files/css/../img/image.jpg\'); }';
		$this->assertEquals($expected, $actual);
	}

	public function testRebaseUrlsIgnoreFrontendController() {
		$this->config->expects($this->once())->method('getSystemValue')->with('htaccess.IgnoreFrontController', false)->willReturn(true);
		$webDir = 'apps/files/css';
		$css = '#id { background-image: url(\'../img/image.jpg\'); }';
		$actual = self::invokePrivate($this->scssCacher, 'rebaseUrls', [$css, $webDir]);
		$expected = '#id { background-image: url(\'../../apps/files/css/../img/image.jpg\'); }';
		$this->assertEquals($expected, $actual);
	}

	public function dataGetCachedSCSS() {
		return [
			['core', 'core/css/styles.scss', '/css/core/styles.css'],
			['files', 'apps/files/css/styles.scss', '/css/files/styles.css']
		];
	}

	/**
	 * @param $appName
	 * @param $fileName
	 * @param $result
	 * @dataProvider dataGetCachedSCSS
	 */
	public function testGetCachedSCSS($appName, $fileName, $result) {
		$this->urlGenerator->expects($this->once())
			->method('linkToRoute')
			->with('core.Css.getCss', [
				'fileName' => 'styles.css',
				'appName' => $appName
			])
			->willReturn(\OC::$WEBROOT . $result);
		$actual = $this->scssCacher->getCachedSCSS($appName, $fileName);
		$this->assertEquals(substr($result, 1), $actual);
	}

}
