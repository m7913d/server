<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Georg Ehrke <oc.list@georgehrke.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV\Tests\unit\CalDAV;

use OC\KnownUser\KnownUserService;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\Proxy\ProxyMapper;
use OCA\DAV\Connector\Sabre\Principal;
use OCA\DAV\Events\CalendarCreatedEvent;
use OCA\DAV\Events\CalendarDeletedEvent;
use OCA\DAV\Events\CalendarObjectCreatedEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use OCP\Share\IManager as ShareManager;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Xml\Property\Href;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Test\TestCase;

/**
 * Class CalDavBackendTest
 *
 * @group DB
 *
 * @package OCA\DAV\Tests\unit\CalDAV
 */
abstract class AbstractCalDavBackend extends TestCase {

	/** @var CalDavBackend */
	protected $backend;

	/** @var Principal | \PHPUnit\Framework\MockObject\MockObject */
	protected $principal;
	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	protected $userManager;
	/** @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject */
	protected $groupManager;
	/** @var EventDispatcherInterface|\PHPUnit\Framework\MockObject\MockObject */
	protected $dispatcher;
	/** @var IEventDispatcher|\PHPUnit\Framework\MockObject\MockObject */
	protected $legacyDispatcher;

	/** @var ISecureRandom */
	private $random;
	/** @var ILogger */
	private $logger;

	public const UNIT_TEST_USER = 'principals/users/caldav-unit-test';
	public const UNIT_TEST_USER1 = 'principals/users/caldav-unit-test1';
	public const UNIT_TEST_GROUP = 'principals/groups/caldav-unit-test-group';
	public const UNIT_TEST_GROUP2 = 'principals/groups/caldav-unit-test-group2';

	protected function setUp(): void {
		parent::setUp();

		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->legacyDispatcher = $this->createMock(EventDispatcherInterface::class);
		$this->principal = $this->getMockBuilder(Principal::class)
			->setConstructorArgs([
				$this->userManager,
				$this->groupManager,
				$this->createMock(ShareManager::class),
				$this->createMock(IUserSession::class),
				$this->createMock(IAppManager::class),
				$this->createMock(ProxyMapper::class),
				$this->createMock(KnownUserService::class),
				$this->createMock(IConfig::class),
			])
			->setMethods(['getPrincipalByPath', 'getGroupMembership'])
			->getMock();
		$this->principal->expects($this->any())->method('getPrincipalByPath')
			->willReturn([
				'uri' => 'principals/best-friend',
				'{DAV:}displayname' => 'User\'s displayname',
			]);
		$this->principal->expects($this->any())->method('getGroupMembership')
			->withAnyParameters()
			->willReturn([self::UNIT_TEST_GROUP, self::UNIT_TEST_GROUP2]);

		$db = \OC::$server->getDatabaseConnection();
		$this->random = \OC::$server->getSecureRandom();
		$this->logger = $this->createMock(ILogger::class);
		$this->config = $this->createMock(IConfig::class);
		$this->backend = new CalDavBackend(
			$db,
			$this->principal,
			$this->userManager,
			$this->groupManager,
			$this->random,
			$this->logger,
			$this->dispatcher,
			$this->legacyDispatcher,
			$this->config
		);

		$this->cleanUpBackend();
	}

	protected function tearDown(): void {
		$this->cleanUpBackend();
		parent::tearDown();
	}

	public function cleanUpBackend() {
		if (is_null($this->backend)) {
			return;
		}
		$this->principal->expects($this->any())->method('getGroupMembership')
			->withAnyParameters()
			->willReturn([self::UNIT_TEST_GROUP, self::UNIT_TEST_GROUP2]);
		$this->cleanupForPrincipal(self::UNIT_TEST_USER);
		$this->cleanupForPrincipal(self::UNIT_TEST_USER1);
	}

	private function cleanupForPrincipal($principal): void {
		$calendars = $this->backend->getCalendarsForUser($principal);
		$this->legacyDispatcher->expects(self::exactly(count($calendars)))
			->method('dispatchTyped')
			->with(self::callback(function ($event) {
				return $event instanceof CalendarDeletedEvent;
			}));
		foreach ($calendars as $calendar) {
			$this->backend->deleteCalendar($calendar['id'], true);
		}
		$subscriptions = $this->backend->getSubscriptionsForUser($principal);
		foreach ($subscriptions as $subscription) {
			$this->backend->deleteSubscription($subscription['id']);
		}
	}

	protected function createTestCalendar() {
		$this->dispatcher->expects(self::once())
			->method('dispatchTyped')
			->with(self::callback(function ($event) {
				return $event instanceof CalendarCreatedEvent;
			}));

		$this->backend->createCalendar(self::UNIT_TEST_USER, 'Example', [
			'{http://apple.com/ns/ical/}calendar-color' => '#1C4587FF'
		]);
		$calendars = $this->backend->getCalendarsForUser(self::UNIT_TEST_USER);
		$this->assertEquals(1, count($calendars));
		$this->assertEquals(self::UNIT_TEST_USER, $calendars[0]['principaluri']);
		/** @var SupportedCalendarComponentSet $components */
		$components = $calendars[0]['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'];
		$this->assertEquals(['VEVENT','VTODO'], $components->getValue());
		$color = $calendars[0]['{http://apple.com/ns/ical/}calendar-color'];
		$this->assertEquals('#1C4587FF', $color);
		$this->assertEquals('Example', $calendars[0]['uri']);
		$this->assertEquals('Example', $calendars[0]['{DAV:}displayname']);
		$calendarId = $calendars[0]['id'];

		return $calendarId;
	}

	protected function createTestSubscription() {
		$this->backend->createSubscription(self::UNIT_TEST_USER, 'Example', [
			'{http://apple.com/ns/ical/}calendar-color' => '#1C4587FF',
			'{http://calendarserver.org/ns/}source' => new Href(['foo']),
		]);
		$calendars = $this->backend->getSubscriptionsForUser(self::UNIT_TEST_USER);
		$this->assertEquals(1, count($calendars));
		$this->assertEquals(self::UNIT_TEST_USER, $calendars[0]['principaluri']);
		$this->assertEquals('Example', $calendars[0]['uri']);
		$calendarId = $calendars[0]['id'];

		return $calendarId;
	}

	protected function createEvent($calendarId, $start = '20130912T130000Z', $end = '20130912T140000Z') {
		$randomPart = self::getUniqueID();

		$calData = <<<EOD
BEGIN:VCALENDAR
VERSION:2.0
PRODID:ownCloud Calendar
BEGIN:VEVENT
CREATED;VALUE=DATE-TIME:20130910T125139Z
UID:47d15e3ec8-$randomPart
LAST-MODIFIED;VALUE=DATE-TIME:20130910T125139Z
DTSTAMP;VALUE=DATE-TIME:20130910T125139Z
SUMMARY:Test Event
DTSTART;VALUE=DATE-TIME:$start
DTEND;VALUE=DATE-TIME:$end
CLASS:PUBLIC
END:VEVENT
END:VCALENDAR
EOD;
		$uri0 = $this->getUniqueID('event');

		$this->dispatcher->expects(self::once())
			->method('dispatchTyped')
			->with(self::callback(function ($event) {
				return $event instanceof CalendarObjectCreatedEvent;
			}));

		$this->backend->createCalendarObject($calendarId, $uri0, $calData);

		return $uri0;
	}

	protected function assertAcl($principal, $privilege, $acl) {
		foreach ($acl as $a) {
			if ($a['principal'] === $principal && $a['privilege'] === $privilege) {
				$this->addToAssertionCount(1);
				return;
			}
		}
		$this->fail("ACL does not contain $principal / $privilege");
	}

	protected function assertNotAcl($principal, $privilege, $acl) {
		foreach ($acl as $a) {
			if ($a['principal'] === $principal && $a['privilege'] === $privilege) {
				$this->fail("ACL contains $principal / $privilege");
				return;
			}
		}
		$this->addToAssertionCount(1);
	}

	protected function assertAccess($shouldHaveAcl, $principal, $privilege, $acl) {
		if ($shouldHaveAcl) {
			$this->assertAcl($principal, $privilege, $acl);
		} else {
			$this->assertNotAcl($principal, $privilege, $acl);
		}
	}
}
