<?php

namespace Unclecheese\EventCalendar;

use SilverStripe\Forms\FieldList;
use SilverStripe\View\Requirements;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\LabelField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\ORM\DataList;

class CalendarEvent extends \Page {

	private static $db = array (
		'Location' => 'Text',
		'Recursion' => 'Boolean',
		'CustomRecursionType' => 'Int',
		'DailyInterval' => 'Int',
		'WeeklyInterval' => 'Int',
		'MonthlyInterval' => 'Int',
		'MonthlyRecursionType1' => 'Int',
		'MonthlyRecursionType2' => 'Int',
		'MonthlyIndex' => 'Int',
		'MonthlyDayOfWeek' => 'Int'
	);

	private static $has_many = array (
		'DateTimes' => CalendarDateTime::class,
		'Exceptions' => RecurringException::class
	);

	private static $many_many = array (
		'RecurringDaysOfWeek' => RecurringDayOfWeek::class,
		'RecurringDaysOfMonth' => RecurringDayOfMonth::class
	);

	private static $icon = "event_calendar/images/event";

	private static $description = "An individual event entry";

	private static $datetime_class = CalendarDateTime::class;

	private static $can_be_root = false;

	public function getCMSFields() {

		$self = $this;

		$this->beforeUpdateCMSFields(function(FieldList $f) use ($self) {
			Requirements::javascript('event_calendar/javascript/calendar_cms.js');
			Requirements::css('event_calendar/css/calendar_cms.css');

			$f->addFieldToTab("Root.Main",
				TextField::create(
					"Location",
					_t('Calendar.LOCATIONDESCRIPTION','The location for this event')
				), 'Content'
			);

			$dt = _t('CalendarEvent.DATESANDTIMES','Dates and Times');
			$recursion = _t('CalendarEvent.RECURSION','Recursion');

			$f->addFieldToTab("Root.$dt",
				GridField::create(
					"DateTimes",
					_t('Calendar.DATETIMEDESCRIPTION','Add dates for this event'),
					$self->DateTimes(),
					GridFieldConfig_RecordEditor::create()
				)
			);

			$f->addFieldsToTab("Root.$recursion", array(
				CheckboxField::create('Recursion',_t('CalendarEvent.REPEATEVENT','Repeat this event'))->addExtraClass('recursion'),
				OptionsetField::create(
					'CustomRecursionType',
					_t('CalendarEvent.DESCRIBEINTERVAL','Describe the interval at which this event recurs.'),
					array (
						'1' => _t('CalendarEvent.DAILY','Daily'),
						'2' => _t('CalendarEvent.WEEKLY','Weekly'),
						'3' => _t('CalendarEvent.MONTHLY','Monthly')
					)
				)->setHasEmptyDefault(true)
			));

			$f->addFieldToTab("Root.$recursion", $dailyInterval = FieldGroup::create(
				LabelField::create($name = "every1", $title = _t("CalendarEvent.EVERY","Every ")),
				DropdownField::create('DailyInterval', '', array_combine(range(1,10), range(1,10))),
				LabelField::create($name = "days",$title = _t("CalendarEvent.DAYS"," day(s)"))
			));

			$f->addFieldToTab("Root.$recursion", $weeklyInterval = FieldGroup::create(
				LabelField::create($name = "every2", $title = _t("CalendarEvent.EVERY","Every ")),
				DropdownField::create('WeeklyInterval', '', array_combine(range(1,10), range(1,10))),
				LabelField::create($name = "weeks", $title = _t("CalendarEvent.WEEKS", " weeks"))
			));

			$f->addFieldToTab("Root.$recursion", CheckboxSetField::create(
				'RecurringDaysOfWeek',
				_t('CalendarEvent.ONFOLLOWINGDAYS','On the following day(s)...'),
				DataList::create(RecurringDayOfWeek::class)->map("ID", "Title")
			));

			$f->addFieldToTab("Root.$recursion", $monthlyInterval = FieldGroup::create(
				LabelField::create($name="every3", $title = _t("CalendarEvent.EVERY", "Every ")),
				DropdownField::create('MonthlyInterval', '', array_combine(range(1,10), range(1,10))),
				LabelField::create($name = "months", $title = _t("CalendarEvent.MONTHS", " month(s)"))
			));

			$f->addFieldsToTab("Root.$recursion", array (
				OptionsetField::create('MonthlyRecursionType1','', array('1' => _t('CalendarEvent.ONTHESEDATES','On these date(s)...')))->setHasEmptyDefault(true),
				CheckboxSetField::create('RecurringDaysOfMonth', '', DataList::create(RecurringDayOfMonth::class)->map("ID", "Value")),
				OptionsetField::create('MonthlyRecursionType2','', array('1' => _t('CalendarEvent.ONTHE','On the...')))->setHasEmptyDefault(true)
			));

			$f->addFieldToTab("Root.$recursion", $monthlyIndex = FieldGroup::create(
				DropdownField::create('MonthlyIndex', '', array (
					'1' => _t('CalendarEvent.FIRST', 'First'),
					'2' => _t('CalendarEvent.SECOND', 'Second'),
					'3' => _t('CalendarEvent.THIRD', 'Third'),
					'4' => _t('CalendarEvent.FOURTH', 'Fourth'),
					'5' => _t('CalendarEvent.LAST', 'Last')
				))->setHasEmptyDefault(true),
				DropdownField::create('MonthlyDayOfWeek','', DataList::create(RecurringDayOfWeek::class)->map('Value', 'Title'))->setHasEmptyDefault(true),
				LabelField::create( $name = "ofthemonth", $title = _t("CalendarEvent.OFTHEMONTH"," of the month."))
			));
			$f->addFieldToTab("Root.$recursion",
				GridField::create(
					'Exceptions',
					_t('CalendarEvent.ANYEXCEPTIONS','Any exceptions to this pattern? Add the dates below.'),
					$self->Exceptions(),
					GridFieldConfig_RecordEditor::create()
				)
			);
			$dailyInterval->addExtraClass('dailyinterval');
			$weeklyInterval->addExtraClass('weeklyinterval');
			$monthlyInterval->addExtraClass('monthlyinterval');
			$monthlyIndex->addExtraClass('monthlyindex');

		});

		$f = parent::getCMSFields();

		return $f;
	}

	public function getRecursionReader() {
		return new RecursionReader($this);
	}

	public function getDateTimeClass() {
		return $this->stat('datetime_class');
	}

	public function CalendarWidget() {
		return $this->Parent()->CalendarWidget();
	}

}
