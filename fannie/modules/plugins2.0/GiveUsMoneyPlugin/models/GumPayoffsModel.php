<?php
/*******************************************************************************

    Copyright 2014 Whole Foods Co-op

    This file is part of Fannie.

    IT CORE is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    IT CORE is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

/**
  @class GumPayoffsModel

  This table lists checks issued. It can
  be related to either GumEquityShares or
  GumLoanAccounts via the respective 
  mapping table.
*/
class GumPayoffsModel extends BasicModel
{

    protected $name = "GumPayoffs";

    protected $columns = array(
    'gumPayoffID' => array('type'=>'INT', 'primary_key'=>true, 'increment'=>true),
    'amount' => array('type'=>'MONEY'),
    'issueDate' => array('type'=>'DATETIME'),
    'checkNumber' => array('type'=>'INT', 'index'=>true),
	);

    /* START ACCESSOR FUNCTIONS */

    public function gumPayoffID()
    {
        if(func_num_args() == 0) {
            if(isset($this->instance["gumPayoffID"])) {
                return $this->instance["gumPayoffID"];
            } else if (isset($this->columns["gumPayoffID"]["default"])) {
                return $this->columns["gumPayoffID"]["default"];
            } else {
                return null;
            }
        } else {
            if (!isset($this->instance["gumPayoffID"]) || $this->instance["gumPayoffID"] != func_get_args(0)) {
                if (!isset($this->columns["gumPayoffID"]["ignore_updates"]) || $this->columns["gumPayoffID"]["ignore_updates"] == false) {
                    $this->record_changed = true;
                }
            }
            $this->instance["gumPayoffID"] = func_get_arg(0);
        }
    }

    public function amount()
    {
        if(func_num_args() == 0) {
            if(isset($this->instance["amount"])) {
                return $this->instance["amount"];
            } else if (isset($this->columns["amount"]["default"])) {
                return $this->columns["amount"]["default"];
            } else {
                return null;
            }
        } else {
            if (!isset($this->instance["amount"]) || $this->instance["amount"] != func_get_args(0)) {
                if (!isset($this->columns["amount"]["ignore_updates"]) || $this->columns["amount"]["ignore_updates"] == false) {
                    $this->record_changed = true;
                }
            }
            $this->instance["amount"] = func_get_arg(0);
        }
    }

    public function issueDate()
    {
        if(func_num_args() == 0) {
            if(isset($this->instance["issueDate"])) {
                return $this->instance["issueDate"];
            } else if (isset($this->columns["issueDate"]["default"])) {
                return $this->columns["issueDate"]["default"];
            } else {
                return null;
            }
        } else {
            if (!isset($this->instance["issueDate"]) || $this->instance["issueDate"] != func_get_args(0)) {
                if (!isset($this->columns["issueDate"]["ignore_updates"]) || $this->columns["issueDate"]["ignore_updates"] == false) {
                    $this->record_changed = true;
                }
            }
            $this->instance["issueDate"] = func_get_arg(0);
        }
    }

    public function checkNumber()
    {
        if(func_num_args() == 0) {
            if(isset($this->instance["checkNumber"])) {
                return $this->instance["checkNumber"];
            } else if (isset($this->columns["checkNumber"]["default"])) {
                return $this->columns["checkNumber"]["default"];
            } else {
                return null;
            }
        } else {
            if (!isset($this->instance["checkNumber"]) || $this->instance["checkNumber"] != func_get_args(0)) {
                if (!isset($this->columns["checkNumber"]["ignore_updates"]) || $this->columns["checkNumber"]["ignore_updates"] == false) {
                    $this->record_changed = true;
                }
            }
            $this->instance["checkNumber"] = func_get_arg(0);
        }
    }
    /* END ACCESSOR FUNCTIONS */
}

