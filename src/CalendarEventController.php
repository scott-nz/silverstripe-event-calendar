<?php

namespace Unclecheese\EventCalendar;

use SilverStripe\ORM\DataList;
use SilverStripe\View\Requirements;

class CalendarEventController extends \PageController
{

    public function init()
    {
        parent::init();
        Requirements::themedCSS('calendar', 'event_calendar');
    }

    public function MultipleDates()
    {
        return DataList::create($this->data()->getDateTimeClass())
                ->filter("EventID", $this->ID)
                ->sort("\"StartDate\" ASC")
                ->count() > 1;
    }

    public function DateAndTime()
    {
        return DataList::create($this->data()->getDateTimeClass())
            ->filter("EventID", $this->ID)
            ->sort("\"StartDate\" ASC");
    }

    public function UpcomingDates($limit = 3)
    {
        return DataList::create($this->data()->getDateTimeClass())
            ->filter("EventID", $this->ID)
            ->where("\"StartDate\" >= DATE(NOW())")
            ->sort("\"StartDate\" ASC")
            ->limit($limit);
    }

    public function OtherDates()
    {
        if (!isset($_REQUEST['date'])) {
            $date_obj = $this->DateAndTime()->first();
            if (!$date_obj) {
                return false;
            } else {
                $date = $date_obj->StartDate;
            }
        } elseif (strtotime($_REQUEST['date']) > 0) {
            $date = date('Y-m-d', strtotime($_REQUEST['date']));
        }

        $cal = $this->Parent();

        if ($this->Recursion == 1) {
            $datetime_obj = DataList::create($this->data()->getDateTimeClass())
                ->where("EventID = {$this->ID}")
                ->first();
            $datetime_obj->StartDate = $date;

            return $cal->getNextRecurringEvents($this, $datetime_obj);
        } else {
            return DataList::create($this->data()->getDateTimeClass())
                ->filter(array(
                    "EventID" => $this->ID
                ))
                ->exclude(array(
                    "StartDate" => $date
                ))
                ->sort("StartDate ASC")
                ->limit($cal->OtherDatesCount);
        }
        return false;
    }


    public function CurrentDate()
    {
        $allDates = DataList::create($this->data()->getDateTimeClass())
            ->filter("EventID", $this->ID)
            ->sort("\"StartDate\" ASC");
        if (!isset($_REQUEST['date'])) {
            // If no date filter specified, return the first one
            return $allDates->first();
        } elseif (strtotime($_REQUEST['date']) > 0) {
            $date = date('Y-m-d', strtotime($_REQUEST['date']));
            if ($this->Recursion) {
                $datetime = $allDates->first();
                if ($datetime) {
                    $datetime->StartDate = $date;
                    $datetime->EndDate = $date;
                    return $datetime;
                }
            }
            return $allDates
                ->filter("StartDate", $date)
                ->first();
        }
    }

}
