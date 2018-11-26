<?php

namespace Unclecheese\EventCalendar;

use SilverStripe\View\Requirements;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataList;
use Altumo\Utils\sfDate\sfDate;
use DateTime;
use DateTimezone;
use SilverStripe\Control\Controller;

class Calendar extends \Page {

	private static $db = array(
		'DefaultDateHeader' => 'Varchar(50)',
		'OtherDatesCount' => 'Int',
		'RSSTitle' => 'Varchar(255)',
		'DefaultFutureMonths' => 'Int',
		'EventsPerPage' => 'Int',
		'DefaultView' => "Enum('today,week,month,weekend,upcoming','upcoming')"
	);

	private static $has_many = array (
		'Announcements' => CalendarAnnouncement::class,
		'Feeds' => ICSFeed::class
	);

	private static $many_many = array (
		'NestedCalendars' => Calendar::class
	);

	private static $belongs_many_many = array (
		'ParentCalendars' => Calendar::class
	);

	private static $allowed_children = array (
		CalendarEvent::class
	);

	private static $defaults = array (
		'DefaultDateHeader' => 'Upcoming Events',
		'OtherDatesCount' => '3',
		'DefaultFutureMonths' => '6',
		'EventsPerPage' => '10',
		'DefaultView' => 'upcoming'
	);

	private static $reccurring_event_index = 0;

	private static $icon = "event_calendar/images/calendar";

	private static $description = "A collection of Calendar Events";

	private static $event_class = CalendarEvent::class;

	private static $announcement_class = CalendarAnnouncement::class;

	private static $timezone = "America/New_York";

	private static $language = "EN";

	public static $jquery_included = false;

	private static $caching_enabled = false;

	protected $eventClass_cache,
			  $announcementClass_cache,
			  $datetimeClass_cache,
			  $dateToEventRelation_cache,
			  $announcementToCalendarRelation_cache,
			  $EventList_cache;

	public static function set_jquery_included($bool = true) {
		self::$jquery_included = $bool;
	}

	public static function enable_caching() {
		self::config()->caching_enabled = true;
	}

	public function getCMSFields() {

		$self = $this;

		$this->beforeUpdateCMSFields(function($f) use ($self) {

			Requirements::javascript('event_calendar/javascript/calendar_cms.js');

			$configuration = _t('Calendar.CONFIGURATION','Configuration');
			$f->addFieldsToTab("Root.$configuration", array(
				DropdownField::create('DefaultView',_t('Calendar.DEFAULTVIEW','Default view'), array (
					'upcoming' => _t('Calendar.UPCOMINGVIEW',"Show a list of upcoming events."),
					'month' => _t('Calendar.MONTHVIEW',"Show this month's events."),
					'week' => _t('Calendar.WEEKVIEW',"Show this week's events. If none, fall back on this month's"),
					'today' => _t('Calendar.TODAYVIEW',"Show today's events. If none, fall back on this week's events"),
					'weekend' => _t('Calendar.WEEKENDVIEW',"Show this weekend's events.")
				))->addExtraClass('defaultView'),
				NumericField::create('DefaultFutureMonths', _t('Calendar.DEFAULTFUTUREMONTHS','Number maximum number of future months to show in default view'))->addExtraClass('defaultFutureMonths'),
				NumericField::create('EventsPerPage', _t('Calendar.EVENTSPERPAGE','Events per page')),
				TextField::create('DefaultDateHeader', _t('Calendar.DEFAULTDATEHEADER','Default date header (displays when no date range has been selected)')),
				NumericField::create('OtherDatesCount', _t('Calendar.NUMBERFUTUREDATES','Number of future dates to show for repeating events'))
			));

			// Announcements
			$announcements = _t('Calendar.Announcements','Announcements');
			$f->addFieldToTab("Root.$announcements", $announcementsField = GridField::create(
					"Announcements",
					$announcements,
					$self->Announcements(),
					GridFieldConfig_RecordEditor::create()
				));
			$announcementsField->setDescription(_t('Calendar.ANNOUNCEMENTDESCRIPTION','Announcements are simple entries you can add to your calendar that do not have detail pages, e.g. "Office closed"'));

			// Feeds
			$feeds = _t('Calendar.FEEDS','Feeds');
			$f->addFieldToTab("Root.$feeds", $feedsField = GridField::create(
				"Feeds",
				$feeds,
				$self->Feeds(),
				GridFieldConfig_RecordEditor::create()
			));
			$feedsField->setDescription(_t('Calendar.ICSFEEDDESCRIPTION','Add ICS feeds to your calendar to include events from external sources, e.g. a Google Calendar'));

			$otherCals = Calendar::get()->exclude(array("ID" => $self->ID));
			if($otherCals->exists()) {
				$f->addFieldToTab("Root.$feeds", new CheckboxSetField(
					'NestedCalendars',
					_t('Calendar.NESTEDCALENDARS','Include events from these calendars'),
					$otherCals->map('ID', 'Link')
				));
			}

			$f->addFieldToTab("Root.Main", new TextField('RSSTitle', _t('Calendar.RSSTITLE','Title of RSS Feed')),'Content');

		});

		$f = parent::getCMSFields();

		return $f;
	}

	public function getEventClass() {
		if($this->eventClass_cache) return $this->eventClass_cache;
		$this->eventClass_cache = $this->stat('event_class');
		return $this->eventClass_cache;
	}

	public function getAnnouncementClass() {
		if($this->announcementClass_cache) return $this->announcementClass_cache;
		$this->announcementClass_cache = $this->stat('announcement_class');
		return $this->announcementClass_cache;
	}

	public function getDateTimeClass() {
		if($this->datetimeClass_cache) return $this->datetimeClass_cache;
		$this->datetimeClass_cache = singleton($this->getEventClass())->stat('datetime_class');
		return $this->datetimeClass_cache;
	}

	public function getDateToEventRelation() {
		if($this->dateToEventRelation_cache) return $this->dateToEventRelation_cache;
		$this->dateToEventRelation_cache = singleton($this->getDateTimeClass())->getReverseAssociation($this->getEventClass())."ID";
		return $this->dateToEventRelation_cache;
	}

	public function getCachedEventList($start, $end, $filter = null, $limit = null) {
		return CachedCalendarEntry::get()
			->filter(array(
				"CachedCalendarID" => $this->ID
			))
			->exclude(array(
				"StartDate:LessThan" => $end,
				"EndDate:GreaterThan" => $start,
			))
			->sort(array(
				"StartDate" => "ASC",
				"StartTime" => "ASC"
			))
			->limit($limit);

	}

	public function getEventList($start, $end, $filter = null, $limit = null, $announcement_filter = null) {
		if(Config::inst()->get(Calendar::class, "caching_enabled")) {
			return $this->getCachedEventList($start, $end, $filter, $limit);
		}

		$eventList = new ArrayList();

		foreach($this->getAllCalendars() as $calendar) {
			if($events = $calendar->getStandardEvents($start, $end, $filter)) {
				$eventList->merge($events);
			}

			$announcements = DataList::create($this->getAnnouncementClass())
				->filter(array(
					"CalendarID" => $calendar->ID,
					"StartDate:LessThan:Not" => $start,
					"EndDate:GreaterThan:Not" => $end,
				));
			if($announcement_filter) {
				$announcements = $announcements->where($announcement_filter);
			}

			if($announcements) {
				foreach($announcements as $announcement) {
					$eventList->push($announcement);
				}
			}

			if($recurring = $calendar->getRecurringEvents($filter)) {
				$eventList = $calendar->addRecurringEvents($start, $end, $recurring, $eventList);
			}

			if($feedevents = $calendar->getFeedEvents($start,$end)) {
				$eventList->merge($feedevents);
			}
		}

		$eventList = $eventList->sort(array("StartDate" => "ASC", "StartTime" => "ASC"));
		$eventList = $eventList->limit($limit);

		return $this->EventList_cache = $eventList;
	}

	protected function getStandardEvents($start, $end, $filter = null) {
		$children = $this->AllChildren();
		$ids = $children->column('ID');
		$datetimeClass = $this->getDateTimeClass();
		$relation = $this->getDateToEventRelation();
		$eventClass = $this->getEventClass();

		$list = DataList::create($datetimeClass)
			->filter(array(
				$relation => $ids
			))
			->innerJoin($eventClass, "$relation = \"{$eventClass}\".\"ID\"")
			->innerJoin("SiteTree", "\"SiteTree\".\"ID\" = \"{$eventClass}\".\"ID\"")
			->where("Recursion != 1");
		if($start && $end) {
			$list = $list->where("
					(StartDate <= '$start' AND EndDate >= '$end') OR
					(StartDate BETWEEN '$start' AND '$end') OR
					(EndDate BETWEEN '$start' AND '$end')
					");
		}
		else if($start) {
			$list = $list->where("(StartDate >= '$start' OR EndDate > '$start')");
		}

		else if($end) {
			$list = $list->where("(EndDate <= '$end' OR StartDate < '$end')");
		}

		if($filter) {
			$list = $list->where($filter);
		}

		return $list;
	}

	protected function getRecurringEvents($filter = null) {
		$event_class = $this->getEventClass();
		$datetime_class = $this->getDateTimeClass();
		if($relation = $this->getDateToEventRelation()) {
			$events = DataList::create($event_class)
				->filter("Recursion", "1")
				->filter("ParentID", $this->ID)
				->innerJoin($datetime_class, "\"{$datetime_class}\".{$relation} = \"SiteTree\".ID");
			if($filter) {
				$events = $events->where($filter);
			}
			return $events;
		}
		return false;
	}

	public function getNextRecurringEvents($event_obj, $datetime_obj, $limit = null) {
		$counter = sfDate::getInstance($datetime_obj->StartDate);
		if($event = $datetime_obj->Event()->DateTimes()->First()) {
			$end_date = strtotime($event->EndDate);
		}
		else {
			$end_date = false;
		}
		$counter->tomorrow();
		$dates = new ArrayList();
		while($dates->Count() != $this->OtherDatesCount) {
			// check the end date
			if($end_date) {
				if($end_date > 0 && $end_date <= $counter->get())
					break;
			}
			if($event_obj->getRecursionReader()->recursionHappensOn($counter->get())) {
				$dates->push($this->newRecursionDateTime($datetime_obj,$counter->date()));
			}
			$counter->tomorrow();
		}
		return $dates;
	}

	protected function addRecurringEvents($start_date, $end_date, $recurring_events,$all_events) {
		$date_counter = sfDate::getInstance($start_date);
		$end = sfDate::getInstance($end_date);
		foreach($recurring_events as $recurring_event) {
			$reader = $recurring_event->getRecursionReader();
			$relation = $recurring_event->getReverseAssociation($this->getDateTimeClass());
			if(!$relation) continue;

			$recurring_event_datetimes = $recurring_event->$relation()->filter(array(
				'StartDate:LessThanOrEqual' => $end->date(),
				'EndDate:GreaterThanOrEqual' => $date_counter->date(),
			));

			foreach ($recurring_event_datetimes as $recurring_event_datetime) {
				$date_counter = sfDate::getInstance($start_date);
				$start = sfDate::getInstance($recurring_event_datetime->StartDate);
				if ($start->get() > $date_counter->get()) {
					$date_counter = $start;
				}
				while($date_counter->get() <= $end->get()){
					// check the end date
					if($recurring_event_datetime->EndDate) {
						$end_stamp = strtotime($recurring_event_datetime->EndDate);
						if($end_stamp > 0 && $end_stamp < $date_counter->get()) {
							break;
						}
					}
					if($reader->recursionHappensOn($date_counter->get())) {
						$e = $this->newRecursionDateTime($recurring_event_datetime, $date_counter->date());
						$all_events->push($e);
					}
					$date_counter->tomorrow();
				}
				$date_counter->reset();
			}
		}
		return $all_events;
	}

	public function newRecursionDateTime($recurring_event_datetime, $start_date) {
		$c = $this->getDateTimeClass();
		$relation = $this->getDateToEventRelation();
		$e = new $c();
		foreach($recurring_event_datetime->db() as $field => $type) {
			$e->$field = $recurring_event_datetime->$field;
		}
		$e->DateTimeID = $recurring_event_datetime->ID;
		$e->StartDate = $start_date;
		$e->EndDate = $start_date;
		$e->$relation = $recurring_event_datetime->$relation;
		$e->ID = "recurring" . self::$reccurring_event_index;
		self::$reccurring_event_index++;
		return $e;
	}


	public function getFeedEvents($start_date, $end_date) {
		$start = new DateTime($start_date);
		// single day views don't pass end dates
		if ($end_date) {
			$end = new DateTime($end_date);
		} else {
			$end = $start;
		}

		$feeds = $this->Feeds();
		$feedevents = new ArrayList();
		foreach( $feeds as $feed ) {
			$feedreader = new ICal( $feed->URL );
			$events = $feedreader->events();
			foreach ( $events as $event ) {
				// translate iCal schema into CalendarAnnouncement schema (datetime + title/content)
				$feedevent = new CalendarAnnouncement;
                //pass ICS feed ID to event list
                $feedevent->ID = 'ICS_'.$feed->ID;
                $feedevent->Feed = true;
                $feedevent->CalendarID = $this->ID;
				$feedevent->Title = $event['SUMMARY'];
				if ( isset($event['DESCRIPTION']) ) {
					$feedevent->Content = $event['DESCRIPTION'];
				}
				$startdatetime = $this->iCalDateToDateTime($event['DTSTART']);//->setTimezone(new DateTimeZone($this->stat('timezone')));
				$enddatetime = $this->iCalDateToDateTime($event['DTEND']);//->setTimezone(new DateTimeZone($this->stat('timezone')));

                //Set event start/end to midnight to allow comparisons below to work
   				$startdatetime->modify('00:00:00');
				$enddatetime->modify('00:00:00');

				if ( ($startdatetime < $start && $enddatetime < $start)
					|| $startdatetime > $end && $enddatetime > $end) {
					// do nothing; dates outside range
				} else {
					$feedevent->StartDate = $startdatetime->format('Y-m-d');
					$feedevent->StartTime = $startdatetime->format('H:i:s');

					$feedevent->EndDate = $enddatetime->format('Y-m-d');
					$feedevent->EndTime = $enddatetime->format('H:i:s');

					$feedevents->push($feedevent);
				}
			}
		}
		return $feedevents;
	}

	public function iCalDateToDateTime($date) {
		$dt = new DateTime($date);
		$dt->setTimeZone( new DateTimezone($this->stat('timezone')) );
		return $dt;
	}


	public function getAllCalendars() {
		$calendars = new ArrayList();
		$calendars->push($this);
		$calendars->merge($this->NestedCalendars());
		return $calendars;
	}

	public function UpcomingEvents($limit = 5, $filter = null) {
		$all = $this->getEventList(
			sfDate::getInstance()->date(),
			sfDate::getInstance()->addMonth($this->DefaultFutureMonths)->date(),
			$filter,
			$limit
		);
		return $all->limit($limit);
	}

	public function UpcomingAnnouncements($limit = 5, $filter = null) {
		return $this->Announcements()
			->filter(array(
				'StartDate:GreaterThan' => 'NOW'
			))
			->where($filter)
			->limit($limit);
	}

	public function RecentEvents($limit = null, $filter = null)  {
		$start_date = sfDate::getInstance();
		$end_date = sfDate::getInstance();
		$l = ($limit === null) ? "9999" : $limit;
		$events = $this->getEventList(
			$start_date->subtractMonth($this->DefaultFutureMonths)->date(),
			$end_date->yesterday()->date(),
			$filter,
			$l
		);
		$events->sort('StartDate','DESC');
		return $events->limit($limit);
	}

	public function CalendarWidget() {
		$calendar = CalendarWidget::create($this);
		$controller = Controller::curr();
		if($controller->class == CalendarController::class || is_subclass_of($controller, CalendarController::class)) {
			if($controller->getView() != "default") {
				if($startDate = $controller->getStartDate()) {
					$calendar->setOption('start', $startDate->format('Y-m-d'));
				}
				if($endDate = $controller->getEndDate()) {
					$calendar->setOption('end', $endDate->format('Y-m-d'));
				}
			}
		}
		return $calendar;
	}

	public function MonthJumpForm() {
		$controller = Controller::curr();
		if($controller->class == CalendarController::class || is_subclass_of($controller, CalendarController::class)) {
			return Controller::curr()->MonthJumpForm();
		}
		$c = new CalendarController($this);
		return $c->MonthJumpForm();
	}

}
