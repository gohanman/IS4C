<?php
/*******************************************************************************

    Copyright 2001, 2004 Wedge Community Co-op

    This file is part of IT CORE.

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
  @class PrehLib
  A horrible, horrible catch-all clutter of functions
*/
class PrehLib extends LibraryClass 
{

/**
  Remove member number from current transaction
*/
static public function clearMember()
{
    CoreState::memberReset();
    $dbc = Database::tDataConnect();
    $dbc->query("UPDATE localtemptrans SET card_no=0,percentDiscount=NULL");
    CoreLocal::set("ttlflag",0);    
    $opts = array('upc'=>'DEL_MEMENTRY');
    TransRecord::add_log_record($opts);
}

/**
  Begin setting a member number for a transaction
  @param $member_number CardNo from custdata
  @return An array. See Parser::default_json()
   for format.

  This function will either assign the number
  to the current transaction or return a redirect
  request to get more input. If you want the
  cashier to verify member name from a list, use
  this function. If you want to force the number
  to be set immediately, use setMember().
*/
static public function memberID($member_number) 
{ 
    $query = "
        SELECT CardNo,
            personNum
        FROM custdata
        WHERE CardNo=" . ((int)$member_number);

    $ret = array(
        "main_frame"=>false,
        "output"=>"",
        "target"=>".baseHeight",
        "redraw_footer"=>false
    );
    
    $dbc = Database::pDataConnect();
    $result = $dbc->query($query);
    $num_rows = $dbc->num_rows($result);

    /**
      If only a single record exists for the member number,
      the member will be set immediately if:
      - the account is the designated, catchall non-member
      - the verifyName setting is disabled
    */
    if ($num_rows == 1) {
        if ($member_number == CoreLocal::get("defaultNonMem") || CoreLocal::get('verifyName') != 1) {
            $row = $dbc->fetch_row($result);
            self::setMember($row["CardNo"], $row["personNum"]);
            $ret['redraw_footer'] = true;
            $ret['output'] = DisplayLib::lastpage();

            if ($member_number != CoreLocal::get('defaultNonMem')) {
                $ret['udpmsg'] = 'goodBeep';
            }

            return $ret;
        }
    }

    /**
      Go to member search page in all other cases.
      If zero matching records are found, member search should be next.
      If multiple records are found, picking the correct name should
      be next.
      If verifyName is enabled, confirming the name should be next.
    */
    $ret['main_frame'] = MiscLib::base_url() . "gui-modules/memlist.php?idSearch=" . $member_number;

    return $ret;
}

/**
  Assign store-specific alternate member message line
  @param $store code for the coop
  @param $member CardNo from custdata
  @param $personNumber personNum from custdata
  @param $row a record from custdata
  @param $chargeOk whether member can store-charge purchases
*/
static public function setAltMemMsg($store, $member, $personNumber, $row, $chargeOk) 
{
    if ($store == 'WEFC_Toronto') {
    /* Doesn't quite allow for StoreCharge/PrePay for regular members
     * either instead of or in addition to CoopCred
     */
        if (isset($row['blueLine'])) {
            $memMsg = $row['blueLine'];
        } else {
            $memMsg = '#'.$member;
        }
        if ($member == CoreLocal::get('defaultNonMem')) {
            CoreLocal::set("memMsg", $memMsg);
            return;
        }

        if ($member < 99000) {

            if (in_array('CoopCred', CoreLocal::get('PluginList'))) {
                $conn = CoopCredLib::ccDataConnect();
                if ($conn !== False) {
                    $ccQ = "SELECT p.programID AS ppID, p.programName, p.tenderType,
                        p.active, p.startDate, p.endDate,
                        p.bankID, p.creditOK, p.inputOK, p.transferOK,
                        m.creditBalance, m.creditLimit, m.creditOK, m.inputOK,
                            m.transferOK, m.isBank
                            ,(m.creditLimit - m.creditBalance) as availCreditBalance
                        FROM CCredMemberships m
                        JOIN CCredPrograms p
                            ON p.programID = m.programID
                            WHERE m.cardNo =?" .
                        " ORDER BY ppID";
                    $ccS = $conn->prepare_statement("$ccQ");
                    if ($ccS === False) {
                        CoreLocal::set("memMsg", $memMsg . "Prep failed");
                        return;
                    }
                    $args = array();
                    $args[] = $member;
                    $ccR = $conn->exec_statement($ccS, $args);
                    if ($ccR === False) {
                        CoreLocal::set("memMsg", $memMsg . "Query failed");
                        return;
                    }
                    if ($conn->num_rows($ccR) == 0) {
                        CoreLocal::set("memMsg", $memMsg);
                        return;
                    }

                    $message = "";
                    while ($row = $conn->fetch_array($ccR)) {
                        $programOK = CoopCredLib::programOK($row['tenderType'], $conn);
                        if ($programOK === True) {
                            $programCode = 'CCred' . CoreLocal::get("CCredProgramID");
                            $tenderKeyCap = (CoreLocal::get("{$programCode}tenderKeyCap") != "")
                                ?  CoreLocal::get("{$programCode}tenderKeyCap")
                                : 'CCr' . CoreLocal::get("CCredProgramID");
                            $programBalance =
                                (CoreLocal::get("{$programCode}availBal")) ?
                                CoreLocal::get("{$programCode}availBal") :
                                CoreLocal::get("{$programCode}availCreditBalance");

                            $message .= " {$tenderKeyCap}: " .  number_format($programBalance,2);
                        }
                        else {
                            $message .= $row['tenderType'] . " not OK";
                        }
                    }
                    if ($message != "") {
                        CoreLocal::set("memMsg", $memMsg . "$message");
                        return;
                    }

                }
            }

            if ($chargeOk == 1) {
                $conn = Database::pDataConnect();
                $query = "SELECT ChargeLimit AS CLimit
                    FROM custdata
                    WHERE personNum=1 AND CardNo = $member";
                $table_def = $conn->table_definition('custdata');
                // 3Jan14 schema may not have been updated
                if (!isset($table_def['ChargeLimit'])) {
                    $query = str_replace('ChargeLimit', 'MemDiscountLimit', $query);
                }
                $result = $conn->query($query);
                $num_rows = $conn->num_rows($result);
                if ($num_rows > 0) {
                    $row2 = $conn->fetch_array($result);
                } else {
                    $row2 = array();
                }

                if (isset($row2['CLimit'])) {
                    $limit = 1.00 * $row2['CLimit'];
                } else {
                    $limit = 0.00;
                }

                // Prepay
                if ($limit == 0.00) {
                    CoreLocal::set("memMsg", $memMsg . _(' : Pre Pay: $') .
                        number_format(((float)CoreLocal::get("availBal") * 1),2)
                    );
                // Store Charge
                } else {
                    CoreLocal::set("memMsg", $memMsg . _(' : Store Charge: $') .
                        number_format(((float)CoreLocal::get("availBal") * 1),2)
                    );
                }
            }

        // Intra-coop transfer
        } else {
            CoreLocal::set("memMsg", $memMsg);
            CoreLocal::set("memMsg", $memMsg . _(' : Intra Coop spent: $') .
               number_format(((float)CoreLocal::get("balance") * 1),2)
            );
        }
    // WEFC_Toronto
    }

    //return $ret;
}

/**
  Assign a member number to a transaction
  @param $member CardNo from custdata
  @param $personNumber personNum from custdata

  See memberID() for more information.
*/
static public function setMember($member, $personNumber, $row=array())
{
    $conn = Database::pDataConnect();

    /**
      Look up the member information here. There's no good 
      reason to have calling code pass in a specially formatted
      row of data
    */
    $query = "
        SELECT 
            CardNo,
            personNum,
            LastName,
            FirstName,
            CashBack,
            Balance,
            Discount,
            ChargeOk,
            WriteChecks,
            StoreCoupons,
            Type,
            memType,
            staff,
            SSI,
            Purchases,
            NumberOfChecks,
            memCoupons,
            blueLine,
            Shown,
            id 
        FROM custdata 
        WHERE CardNo = " . ((int)$member) . "
            AND personNum = " . ((int)$personNumber);
    $result = $conn->query($query);
    $row = $conn->fetch_row($result);

    CoreLocal::set("memberID",$member);

    CoreLocal::set("memType",$row["memType"]);
    CoreLocal::set("lname",$row["LastName"]);
    CoreLocal::set("fname",$row["FirstName"]);
    CoreLocal::set("Type",$row["Type"]);
    CoreLocal::set("isStaff",$row["staff"]);
    CoreLocal::set("SSI",$row["SSI"]);
    if (CoreLocal::get("Type") == "PC") {
        CoreLocal::set("isMember",1);
    } else {
        CoreLocal::set("isMember",0);
    }

    /**
      Optinonally use memtype table to normalize attributes
      by member type
    */
    if (CoreLocal::get('useMemTypeTable') == 1 && $conn->table_exists('memtype')) {
        $prep = $conn->prepare('SELECT discount, staff, ssi 
                                FROM memtype
                                WHERE memtype=?');
        $res = $conn->execute($prep, array((int)CoreLocal::get('memType')));
        if ($conn->num_rows($res) > 0) {
            $mt_row = $conn->fetch_row($res);
            $row['Discount'] = $mt_row['discount'];
            CoreLocal::set('isStaff', $mt_row['staff']);
            CoreLocal::set('SSI', $mt_row['ssi']);
        }
    }
    if (CoreLocal::get("isStaff") == 0) {
        CoreLocal::set("staffSpecial", 0);
    }

    /**
      Determine what string is shown in the upper
      left of the screen to indicate the current member
    */
    $memMsg = '#' . $member;
    if (!empty($row['blueLine'])) {
        $memMsg = $row['blueLine'];
    }
    $chargeOk = self::chargeOk();
    if (CoreLocal::get("balance") != 0 && $member != CoreLocal::get("defaultNonMem")) {
        $memMsg .= _(" AR");
    }
    if (CoreLocal::get("SSI") == 1) {
        $memMsg .= " #";
    }
    if ($conn->tableExists('CustomerNotifications')) {
        $blQ = '
            SELECT message
            FROM CustomerNotifications
            WHERE cardNo=' . ((int)$member) . '
                AND type=\'blueline\'
            ORDER BY message';
        $blR = $conn->query($blQ);
        while ($blW = $conn->fetchRow($blR)) {
            $memMsg .= ' ' . $blW['message'];
        }
    }
    CoreLocal::set("memMsg",$memMsg);
    self::setAltMemMsg(CoreLocal::get("store"), $member, $personNumber, $row, $chargeOk);

    /**
      Set member number and attributes
      in the current transaction
    */
    $conn2 = Database::tDataConnect();
    $memquery = "
        UPDATE localtemptrans 
        SET card_no = '" . $member . "',
            memType = " . sprintf("%d",CoreLocal::get("memType")) . ",
            staff = " . sprintf("%d",CoreLocal::get("isStaff"));
    $conn2->query($memquery);

    /**
      Add the member discount
    */
    if (CoreLocal::get('discountEnforced')) {
        // skip subtotaling automatically since that occurs farther down
        DiscountModule::updateDiscount(new DiscountModule($row['Discount'], 'custdata'), false);
    }

    /**
      Log the member entry
    */
    CoreLocal::set("memberID",$member);
    $opts = array('upc'=>'MEMENTRY','description'=>'CARDNO IN NUMFLAG','numflag'=>$member);
    TransRecord::add_log_record($opts);

    /**
      Optionally add a subtotal line depending
      on member_subtotal setting.
    */
    if (CoreLocal::get('member_subtotal') === 0 || CoreLocal::get('member_subtotal') === '0') {
        $noop = "";
    } else {
        self::ttl();
    } 
}

/**
  Check if the member has overdue store charge balance
  @param $cardno member number
  @return True or False

  The logic for what constitutes past due has to be built
  into the unpaid_ar_today view. Without that this function
  doesn't really do much.
*/
static public function checkUnpaidAR($cardno)
{
    // only attempt if server is available
    // and not the default non-member
    if ($cardno == CoreLocal::get("defaultNonMem")) return false;
    if (CoreLocal::get("balance") == 0) return false;

    $dbc = Database::mDataConnect();

    if (!$dbc->table_exists("unpaid_ar_today")) return false;

    $query = "SELECT old_balance,recent_payments FROM unpaid_ar_today
        WHERE card_no = $cardno";
    $result = $dbc->query($query);

    // should always be a row, but just in case
    if ($dbc->num_rows($result) == 0) return false;
    $row = $dbc->fetch_row($result);

    $bal = $row["old_balance"];
    $paid = $row["recent_payments"];
    if (CoreLocal::get("memChargeTotal") > 0) {
        $paid += CoreLocal::get("memChargeTotal");
    }
    
    if ($bal <= 0) return false;
    if ($paid >= $bal) return false;

    // only case where customer prompt should appear
    if ($bal > 0 && $paid < $bal){
        CoreLocal::set("old_ar_balance",$bal - $paid);
        return true;
    }

    // just in case i forgot anything...
    return false;
}

static public function check_unpaid_ar($cardno)
{
    return self::checkUnpaidAR($cardno);
}

/**
  Check if an item is voided or a refund
  @param $num item trans_id in localtemptrans
  @return array of status information with keys:
   - voided (int)
   - scaleprice (numeric)
   - discountable (int)
   - discounttype (int)
   - caseprice (numeric)
   - refund (boolean)
   - status (string)
*/
static public function checkstatus($num) 
{
    $ret = array(
        'voided' => 0,
        'scaleprice' => 0,
        'discountable' => 0,
        'discounttype' => 0,
        'caseprice' => 0,
        'refund' => false,
        'status' => ''
    );

    $query = "select voided,unitPrice,discountable,
        discounttype,trans_status
        from localtemptrans where trans_id = ".((int)$num);

    $dbc = Database::tDataConnect();
    $result = $dbc->query($query);

    if ($result && $dbc->numRows($result) > 0) {
        $row = $dbc->fetch_array($result);

        $ret['voided'] = $row['voided'];
        $ret['scaleprice'] = $row['unitPrice'];
        $ret['discountable'] = $row['discountable'];
        $ret['discounttype'] = $row['discounttype'];
        $ret['caseprice'] = $row['unitPrice'];

        if ($row["trans_status"] == "V") {
            $ret['status'] = 'V';
        } elseif ($row["trans_status"] == "R") {
            CoreLocal::set("refund",1);
            CoreLocal::set("autoReprint",1);
            $ret['status'] = 'R';
            $ret['refund'] = true;
        }
    }
    
    return $ret;
}

static private function getTenderMods($right)
{
    $ret = array('TenderModule');

    /**
      Get a tender-specific module if
      one has been configured
    */
    $map = CoreLocal::get("TenderMap");
    $dbc = Database::pDataConnect();
    /**
      Fetch module mapping from the database
      if the schema supports it
      16Mar2015
    */
    $tender_table = $dbc->table_definition('tenders');
    if (isset($tender_table['TenderModule'])) {
        $tender_model = new TendersModel($dbc);
        $map = $tender_model->getMap();
    }
    if (is_array($map) && isset($map[$right])) {
        $class = $map[$right];
        if ($class != 'TenderModule') {
            $ret[] = $class;
        }
    }

    return $ret;
}

/**
  Add a tender to the transaction

  @right tender amount in cents (100 = $1)
  @strl tender code from tenders table
  @return An array see Parser::default_json()
   for format explanation.

  This function will automatically end a transaction
  if the amount due becomes <= zero.
*/
static public function tender($right, $strl)
{
    $ret = array('main_frame'=>false,
        'redraw_footer'=>false,
        'target'=>'.baseHeight',
        'output'=>"");

    $strl = MiscLib::centStrToDouble($strl);

    if (CoreLocal::get('RepeatAgain')) {
        // the default tender prompt utilizes boxMsg2.php to
        // repeat the previous input, plus amount, on confirmation
        // the tender's preReqCheck methods will need to pretend
        // this is the first input rather than a repeat
        CoreLocal::set('msgrepeat', 0);
        CoreLocal::set('RepeatAgain', false);
    }

    $tender_mods = self::getTenderMods($right);
    $tender_object = null;
    foreach ($tender_mods as $class) {
        if (!class_exists($class)) {
            $ret['output'] = DisplayLib::boxMsg(
                _('tender is misconfigured'),
                _('Notify Administrator'),
                false,
                DisplayLib::standardClearButton()
            );
            return $ret;
        } 
        $tender_object = new $class($right, $strl);
        /**
          Do tender-specific error checking and prereqs
        */
        $error = $tender_object->ErrorCheck();
        if ($error !== true) {
            $ret['output'] = $error;
            return $ret;
        }
        $prereq = $tender_object->PreReqCheck();
        if ($prereq !== true) {
            $ret['main_frame'] = $prereq;
            return $ret;
        }
    }

    // add the tender record
    $tender_object->Add();
    Database::getsubtotals();

    // see if transaction has ended
    if (CoreLocal::get("amtdue") <= 0.005) {

        CoreLocal::set("change",-1 * CoreLocal::get("amtdue"));
        $cash_return = CoreLocal::get("change");
        TransRecord::addchange($cash_return, $tender_object->ChangeType(), $tender_object->ChangeMsg());
                    
        CoreLocal::set("End",1);
        $ret['receipt'] = 'full';
        $ret['output'] = DisplayLib::printReceiptFooter();
        TransRecord::finalizeTransaction();
    } else {
        CoreLocal::set("change",0);
        CoreLocal::set("fntlflag",0);
        Database::setglobalvalue("FntlFlag", 0);
        $chk = self::ttl();
        if ($chk === true) {
            $ret['output'] = DisplayLib::lastpage();
        } else {
            $ret['main_frame'] = $chk;
        }
    }
    $ret['redraw_footer'] = true;

    return $ret;
}

/**
  Add an open ring to a department
  @param $price amount in cents (100 = $1)
  @param $dept POS department
  @ret an array of return values
  @returns An array. See Parser::default_json()
   for format explanation.
*/
static public function deptkey($price, $dept,$ret=array()) 
{
    if (CoreLocal::get("quantity") == 0 && CoreLocal::get("multiple") == 0) {
        CoreLocal::set("quantity",1);
    }

    $ringAsCoupon = false;
    if (substr($price,0,2) == 'MC') {
        $ringAsCoupon = true;
        $price = substr($price,2);
    }
        
    if (!is_numeric($dept) || !is_numeric($price) || strlen($price) < 1 || strlen($dept) < 2) {
        $ret['output'] = DisplayLib::inputUnknown();
        CoreLocal::set("quantity",1);
        $ret['udpmsg'] = 'errorBeep';
        return $ret;
    }

    $strprice = $price;
    $strdept = $dept;
    $price = $price/100;
    $dept = $dept/10;
    $discount = 0;

    if (CoreLocal::get("casediscount") > 0 && CoreLocal::get("casediscount") <= 100) {
        $case_discount = (100 - CoreLocal::get("casediscount"))/100;
        $price = $case_discount * $price;
    } elseif (CoreLocal::get('itemPD') > 0 && CoreLocal::get('SecurityLineItemDiscount') == 30 && CoreLocal::get('msgrepeat')==0){
        $ret['main_frame'] = MiscLib::baseURL() . "gui-modules/adminlogin.php?class=LineItemDiscountAdminLogin";
        return $ret;
    } elseif (CoreLocal::get('itemPD') > 0) {
        $discount = MiscLib::truncate2($price * (CoreLocal::get('itemPD')/100.00));
        $price -= $discount;
    }
    $discount = $discount * CoreLocal::get('quantity');

    $query = "SELECT dept_no,
        dept_name,
        dept_tax,
        dept_fs,
        dept_limit,
        dept_minimum,
        dept_discount,";
    $dbc = Database::pDataConnect();
    $table = $dbc->table_definition('departments');
    if (isset($table['dept_see_id'])) {
        $query .= 'dept_see_id,';
    } else {
        $query .= '0 as dept_see_id,';
    }
    if (isset($table['memberOnly'])) {
        $query .= 'memberOnly';
    } else {
        $query .= '0 AS memberOnly';
    }
    $query .= " FROM departments 
                WHERE dept_no = " . ((int)$dept);
    $result = $dbc->query($query);

    $num_rows = $dbc->num_rows($result);
    if ($num_rows == 0) {
        $ret['output'] = DisplayLib::boxMsg(
            _("department unknown"),
            '',
            false,
            DisplayLib::standardClearButton()
        );
        $ret['udpmsg'] = 'errorBeep';
        CoreLocal::set("quantity",1);
    } elseif ($ringAsCoupon) {
        $row = $dbc->fetch_array($result);
        $ret = self::deptCouponRing($row, $price, $ret);
    } else {
        $row = $dbc->fetch_array($result);
        $my_url = MiscLib::baseURL();

        if ($row['dept_see_id'] > 0) {
            list($bad_age, $ret) = PrehLib::ageCheck($row['dept_see_id'], $ret);
            if ($bad_age === true) {
                return $ret;
            }
        }

        $ret = self::deptOpenRing($row, $price, $discount, $ret);
    }

    CoreLocal::set("quantity",0);
    CoreLocal::set("itemPD",0);

    return $ret;
}

static private function deptCouponRing($dept, $price, $ret)
{
    $query2 = "select department, sum(total) as total from localtemptrans where department = "
        .$dept['dept_no']." group by department";

    $db2 = Database::tDataConnect();
    $result2 = $db2->query($query2);

    $num_rows2 = $db2->num_rows($result2);
    if ($num_rows2 == 0) {
        $ret['output'] = DisplayLib::boxMsg(
            _("no item found in")."<br />".$dept["dept_name"],
            '',
            false,
            DisplayLib::standardClearButton()
        );
        $ret['udpmsg'] = 'errorBeep';
    } else {
        $row2 = $db2->fetch_array($result2);
        if ($price > $row2["total"]) {
            $ret['output'] = DisplayLib::boxMsg(
                _("coupon amount greater than department total"),
                '',
                false,
                DisplayLib::standardClearButton()
            );
            $ret['udpmsg'] = 'errorBeep';
        } else {
            TransRecord::addRecord(array(
                'description' => $dept['dept_name'] . ' Coupon',
                'trans_type' => 'I',
                'trans_subtype' => 'CP',
                'trans_status' => 'C',
                'department' => $dept['dept_no'],
                'quantity' => 1,
                'ItemQtty' => 1,
                'unitPrice' => -1 * $price,
                'total' => -1 * $price,
                'regPrice' => -1 * $price,
                'voided' => 0,
            ));
            CoreLocal::set("ttlflag",0);
            $ret['output'] = DisplayLib::lastpage();
            $ret['redraw_footer'] = True;
            $ret['udpmsg'] = 'goodBeep';
        }
    }

    return $ret;
}

static private function deptOpenRing($dept, $price, $discount, $ret)
{
    /**
      Enforce memberOnly flag
    */
    if ($dept['memberOnly'] > 0) {
        switch ($dept['memberOnly']) {
            case 1: // member only, no override
                if (CoreLocal::get('isMember') == 0) {
                    $ret['output'] = DisplayLib::boxMsg(_(
                                        _('Department is member-only'),
                                        _('Enter member number first'),
                                        false,
                                        array('Member Search [ID]' => 'parseWrapper(\'ID\');', 'Dismiss [clear]' => 'parseWrapper(\'CL\');')
                                    ));
                    return $ret;
                }
                break; 
            case 2: // member only, can override
                if (CoreLocal::get('isMember') == 0) {
                    if (CoreLocal::get('msgrepeat') == 0 || CoreLocal::get('lastRepeat') != 'memberOnlyDept') {
                        CoreLocal::set('boxMsg', _(
                            'Department is member-only<br />' .
                            '[enter] to continue, [clear] to cancel'
                        ));
                        CoreLocal::set('lastRepeat', 'memberOnlyDept');
                        $ret['main_frame'] = $my_url . 'gui-modules/boxMsg2.php';
                        return $ret;
                    } else if (CoreLocal::get('lastRepeat') == 'memberOnlyDept') {
                        CoreLocal::set('lastRepeat', '');
                    }
                }
                break;
            case 3: // anyone but default non-member
                if (CoreLocal::get('memberID') == '0') {
                    $ret['output'] = DisplayLib::boxMsg(_(
                                        _('Department is member-only'),
                                        _('Enter member number first'),
                                        false,
                                        array('Member Search [ID]' => 'parseWrapper(\'ID\');', 'Dismiss [clear]' => 'parseWrapper(\'CL\');')
                                    ));
                    return $ret;
                } else if (CoreLocal::get('memberID') == CoreLocal::get('defaultNonMem')) {
                    $ret['output'] = DisplayLib::boxMsg(_(
                                        _('Department not allowed with this member'),
                                        '',
                                        false,
                                        DisplayLib::standardClearButton()
                                    ));
                    return $ret;
                }
                break;
        }
    }

    $deptmax = $dept['dept_limit'] ? $dept['dept_limit'] : 0;
    $deptmin = $dept['dept_minimum'] ? $dept['dept_minimum'] : 0;

    $tax = $dept["dept_tax"];
    $foodstamp = $dept['dept_fs'] != 0 ? 1 : 0;
    $deptDiscount = $dept["dept_discount"];
    list($tax, $foodstamp, $deptDiscount) = self::applyToggles($tax, $foodstamp, $deptDiscount);

    if ($price > $deptmax && (CoreLocal::get('OpenRingHardMinMax') || CoreLocal::get("msgrepeat") == 0)) {
        CoreLocal::set("boxMsg","$".$price." "._("is greater than department limit"));
        $maxButtons = array(
            'Confirm [enter]' => '$(\'#reginput\').val(\'\');submitWrapper();',
            'Cancel [clear]' => '$(\'#reginput\').val(\'CL\');submitWrapper();',
        );
        // remove Confirm button/text if hard limits enforced
        if (CoreLocal::get('OpenRingHardMinMax')) {
            array_shift($maxButtons);
        }
        CoreLocal::set('boxMsgButtons', $maxButtons);
        $ret['main_frame'] = MiscLib::base_url().'gui-modules/boxMsg2.php';
    } elseif ($price < $deptmin && (CoreLocal::get('OpenRingHardMinMax') || CoreLocal::get("msgrepeat") == 0)) {
        CoreLocal::set("boxMsg","$".$price." "._("is lower than department minimum"));
        $minButtons = array(
            'Confirm [enter]' => '$(\'#reginput\').val(\'\');submitWrapper();',
            'Cancel [clear]' => '$(\'#reginput\').val(\'CL\');submitWrapper();',
        );
        if (CoreLocal::get('OpenRingHardMinMax')) {
            array_shift($minButtons);
        }
        CoreLocal::set('boxMsgButtons', $minButtons);
        $ret['main_frame'] = MiscLib::base_url().'gui-modules/boxMsg2.php';
    } else {
        if (CoreLocal::get("casediscount") > 0) {
            TransRecord::addcdnotify();
            CoreLocal::set("casediscount",0);
        }
        
        TransRecord::addRecord(array(
            'upc' => $price . 'DP' . $dept['dept_no'],
            'description' => $dept['dept_name'],
            'trans_type' => 'D',
            'department' => $dept['dept_no'],
            'quantity' => CoreLocal::get('quantity'),
            'ItemQtty' => CoreLocal::get('quantity'),
            'unitPrice' => $price,
            'total' => $price * CoreLocal::get('quantity'),
            'regPrice' => $price,
            'tax' => $tax,
            'foodstamp' => $foodstamp,
            'discountable' => $deptDiscount,
            'voided' => 0,
            'discount' => $discount,
        ));
        CoreLocal::set("ttlflag",0);
        CoreLocal::set("msgrepeat",0);

        if (CoreLocal::get("itemPD") > 0) {
            TransRecord::adddiscount($discount, $dept);
        }

        $ret['output'] = DisplayLib::lastpage();
        $ret['redraw_footer'] = true;
        $ret['udpmsg'] = 'goodBeep';
    }

    return $ret;
}

static private function addRemoveDiscountViews()
{
    $dbc = Database::tDataConnect();
    if (CoreLocal::get("isMember") == 1 || CoreLocal::get("memberID") == CoreLocal::get("visitingMem")) {
        $cols = Database::localMatchingColumns($dbc,"localtemptrans","memdiscountadd");
        $dbc->query("INSERT INTO localtemptrans ({$cols}) SELECT {$cols} FROM memdiscountadd");
    } else {
        $cols = Database::localMatchingColumns($dbc,"localtemptrans","memdiscountremove");
        $dbc->query("INSERT INTO localtemptrans ({$cols}) SELECT {$cols} FROM memdiscountremove");
    }

    if (CoreLocal::get("isStaff") != 0) {
        $cols = Database::localMatchingColumns($dbc,"localtemptrans","staffdiscountadd");
        $dbc->query("INSERT INTO localtemptrans ({$cols}) SELECT {$cols} FROM staffdiscountadd");
    } else {
        $cols = Database::localMatchingColumns($dbc,"localtemptrans","staffdiscountremove");
        $dbc->query("INSERT INTO localtemptrans ({$cols}) SELECT {$cols} FROM staffdiscountremove");
    }
}

/**
  Total the transaction
  @return
   True - total successfully
   String - URL

  If ttl() returns a string, go to that URL for
  more information on the error or to resolve the
  problem. 

  The most common error, by far, is no 
  member number in which case the return value
  is the member-entry page.
*/
static public function ttl() 
{
    if (CoreLocal::get("memberID") == "0") {
        return MiscLib::base_url()."gui-modules/memlist.php";
    } 

    self::addRemoveDiscountViews();

    CoreLocal::set("ttlflag",1);
    Database::setglobalvalue("TTLFlag", 1);

    // if total is called before records have been added to the transaction,
    // Database::getsubtotals will zero out the discount
    $savePD = CoreLocal::get('percentDiscount');

    // Refresh totals after staff and member discounts.
    Database::getsubtotals();

    $ttlHooks = CoreLocal::get('TotalActions');
    if (is_array($ttlHooks)) {
        foreach($ttlHooks as $ttl_class) {
            if ("$ttl_class" == "") {
                continue;
            }
            if (!class_exists($ttl_class)) {
                CoreLocal::set("boxMsg",sprintf("TotalActions class %s doesn't exist.", $ttl_class));
                CoreLocal::set('boxMsgButtons', array(
                    'Dismiss [clear]' => '$(\'#reginput\').val(\'CL\');submitWrapper();',
                ));
                return MiscLib::baseURL()."gui-modules/boxMsg2.php?quiet=1";
            }
            $mod = new $ttl_class();
            $result = $mod->apply();
            if ($result !== true && is_string($result)) {
                return $result; // redirect URL
            }
        }
    }

    // Refresh totals after total actions
    Database::getsubtotals();

    CoreLocal::set('percentDiscount', $savePD);

    if (CoreLocal::get("percentDiscount") > 0) {
        if (CoreLocal::get('member_subtotal') === 0 || CoreLocal::get('member_subtotal') === '0') {
            // 5May14 Andy
            // Why is this different trans_type & voided from
            // the other Subtotal record generated farther down?
            TransRecord::addRecord(array(
                'description' => 'Subtotal',
                'trans_type' => '0',
                'trans_status' => 'D',
                'unitPrice' => MiscLib::truncate2(CoreLocal::get('transDiscount') + CoreLocal::get('subtotal')),
                'voided' => 7,
            ));
        }
        TransRecord::discountnotify(CoreLocal::get("percentDiscount"));
        TransRecord::addRecord(array(
            'description' => CoreLocal::get('percentDiscount') . '% Discount',
            'trans_type' => 'C',
            'trans_status' => 'D',
            'unitPrice' => MiscLib::truncate2(-1 * CoreLocal::get('transDiscount')),
            'voided' => 5,
        ));
    }

    $amtDue = str_replace(",", "", CoreLocal::get("amtdue"));

    $memline = "";
    if(CoreLocal::get("memberID") != CoreLocal::get("defaultNonMem")) {
        $memline = " #" . CoreLocal::get("memberID");
    } 
    // temporary fix Andy 13Feb13
    // my cashiers don't like the behavior; not configurable yet
    if (CoreLocal::get("store") == "wfc") $memline="";
    TransRecord::addRecord(array(
        'description' => 'Subtotal ' 
                         . MiscLib::truncate2(CoreLocal::get('subtotal')) 
                         . ', Tax' 
                         . MiscLib::truncate2(CoreLocal::get('taxTotal')) 
                         . $memline,
        'trans_type' => 'C',
        'trans_status' => 'D',
        'unitPrice' => $amtDue,
        'voided' => 3,
    ));

    if (CoreLocal::get("fntlflag") == 1) {
        TransRecord::addRecord(array(
            'description' => 'Foodstamps Eligible',
            'trans_type' => '0',
            'trans_status' => 'D',
            'unitPrice' => MiscLib::truncate2(CoreLocal::get('fsEligible')),
            'voided' => 7,
        ));
    }

    return true;
}

/**
  Total the transaction, which the cashier thinks may be eligible for the
     Ontario Meal Tax Rebate.
  @return
   True - total successfully
   String - URL

  If ttl() returns a string, go to that URL for
  more information on the error or to resolve the
  problem. 

  The most common error, by far, is no 
  member number in which case the return value
  is the member-entry page.

  The Ontario Meal Tax Rebate refunds the provincial part of the
  Harmonized Sales Tax if the total of the transaction is not more
  than a certain amount.

  If the transaction qualifies,
   change the tax status for each item at the higher rate to the lower rate.
   Display a message that a change was made.
  Otherwise display a message about that.
  Total the transaction as usual.

*/
static public function omtr_ttl() 
{
    // Must have gotten member number before totaling.
    if (CoreLocal::get("memberID") == "0") {
        return MiscLib::base_url()."gui-modules/memlist.php";
    }
    else {
        self::addRemoveDiscountViews();

        CoreLocal::set("ttlflag",1);
        Database::setglobalvalue("TTLFlag", 1);

        // Refresh totals after staff and member discounts.
        Database::getsubtotals();

        // Is the before-tax total within range?
        if (CoreLocal::get("runningTotal") <= 4.00 ) {
            $totalBefore = CoreLocal::get("amtdue");
            $ret = Database::changeLttTaxCode("HST","GST");
            if ( $ret !== True ) {
                TransRecord::addcomment("$ret");
            } else {
                Database::getsubtotals();
                $saved = ($totalBefore - CoreLocal::get("amtdue"));
                $comment = sprintf("OMTR OK. You saved: $%.2f", $saved);
                TransRecord::addcomment("$comment");
            }
        }
        else {
            TransRecord::addcomment("Does NOT qualify for OMTR");
        }

        /* If member can do Store Charge, warn on certain conditions.
         * Important preliminary is to refresh totals.
        */
        $temp = self::chargeOk();
        if (CoreLocal::get("balance") < CoreLocal::get("memChargeTotal") && CoreLocal::get("memChargeTotal") > 0){
            if (CoreLocal::get('msgrepeat') == 0){
                CoreLocal::set("boxMsg",sprintf("<b>A/R Imbalance</b><br />
                    Total AR payments $%.2f exceeds AR balance %.2f<br />",
                    CoreLocal::get("memChargeTotal"),
                    CoreLocal::get("balance")));
                CoreLocal::set('boxMsgButtons', array(
                    'Confirm [enter]' => '$(\'#reginput\').val(\'\');submitWrapper();',
                    'Cancel [clear]' => '$(\'#reginput\').val(\'CL\');submitWrapper();',
                ));
                CoreLocal::set("strEntered","TL");
                return MiscLib::base_url()."gui-modules/boxMsg2.php?quiet=1";
            }
        }

        // Display discount.
        if (CoreLocal::get("percentDiscount") > 0) {
            TransRecord::addRecord(array(
                'description' => CoreLocal::get('percentDiscount') . '% Discount',
                'trans_type' => 'C',
                'trans_status' => 'D',
                'unitPrice' => MiscLib::truncate2(-1 * CoreLocal::get('transDiscount')),
                'voided' => 5,
            ));
        }

        $amtDue = str_replace(",", "", CoreLocal::get("amtdue"));

        // Compose the member ID string for the description.
        if(CoreLocal::get("memberID") != CoreLocal::get("defaultNonMem")) {
            $memline = " #" . CoreLocal::get("memberID");
        } 
        else {
            $memline = "";
        }

        // Put out the Subtotal line.
        $peek = self::peekItem();
        if (True || substr($peek,0,9) != "Subtotal "){
            TransRecord::addRecord(array(
                'description' => 'Subtotal ' 
                                 . MiscLib::truncate2(CoreLocal::get('subtotal')) 
                                 . ', Tax' 
                                 . MiscLib::truncate2(CoreLocal::get('taxTotal')) 
                                 . $memline,
                'trans_type' => 'C',
                'trans_status' => 'D',
                'unitPrice' => $amtDue,
                'voided' => 3,
            ));
        }
    
        if (CoreLocal::get("fntlflag") == 1) {
            TransRecord::addRecord(array(
                'description' => 'Foodstamps Eligible',
                'trans_type' => '0',
                'trans_status' => 'D',
                'unitPrice' => MiscLib::truncate2(CoreLocal::get('fsEligible')),
                'voided' => 7,
            ));
        }

    }

    return True;

// omtr_ttl
}

/**
  Calculate WIC eligible total
  @return [number] WIC eligible items total
*/
static public function wicableTotal()
{
    $dbc = Database::tDataConnect();
    $products = CoreLocal::get('pDatabase') . $dbc->sep() . 'products';

    $query = '
        SELECT SUM(total) AS wicableTotal
        FROM localtemptrans AS t
            INNER JOIN ' . $products . ' AS p ON t.upc=p.upc
        WHERE t.trans_type = \'I\'
            AND p.wicable = 1
    ';

    $result = $dbc->query($query);
    if (!$result || $dbc->num_rows($result) == 0) {
        return 0.00;
    } else {
        $row = $dbc->fetch_row($result);
        
        return $row['wicableTotal'];
    }
}

/**
  See what the last item in the transaction is currently
  @param $full_record [boolean] return full database record.
    Default is false. Just returns description.
  @return localtemptrans.description for the last item
    or localtemptrans record for the last item

    If no record exists, returns false
*/
static public function peekItem($full_record=false, $transID=false)
{
    $dbc = Database::tDataConnect();
    $query = "SELECT * FROM localtemptrans ";
    if ($transID) {
        $query .= ' WHERE trans_id=' . ((int)$transID);
    }
    $query .= " ORDER BY trans_id DESC";
    $res = $dbc->query($query);
    $row = $dbc->fetch_row($res);

    if ($full_record) {
        return is_array($row) ? $row : false;
    } else {
        return isset($row['description']) ? $row['description'] : false;
    }
}

/**
  Add tax and transaction discount records.
  This is called at the end of a transaction.
  There's probably no other place where calling
  this function is appropriate.
*/
static public function finalttl() 
{
    if (CoreLocal::get("percentDiscount") > 0) {
        TransRecord::addRecord(array(
            'description' => 'Discount',
            'trans_type' => 'C',
            'trans_status' => 'D',
            'unitPrice' => MiscLib::truncate2(-1 * CoreLocal::get('transDiscount')),
            'voided' => 5,
        ));
    }

    TransRecord::addRecord(array(
        'upc' => 'Subtotal',
        'description' => 'Subtotal',
        'trans_type' => 'C',
        'trans_status' => 'D',
        'unitPrice' => MiscLib::truncate2(CoreLocal::get('taxTotal') - CoreLocal::get('fsTaxExempt')),
        'voided' => 11,
    ));


    if (CoreLocal::get("fsTaxExempt")  != 0) {
        TransRecord::addRecord(array(
            'upc' => 'Tax',
            'description' => 'FS Taxable',
            'trans_type' => 'C',
            'trans_status' => 'D',
            'unitPrice' => MiscLib::truncate2(CoreLocal::get('fsTaxExempt')),
            'voided' => 7,
        ));
    }

    TransRecord::addRecord(array(
        'upc' => 'Total',
        'description' => 'Total',
        'trans_type' => 'C',
        'trans_status' => 'D',
        'unitPrice' => MiscLib::truncate2(CoreLocal::get('amtdue')),
        'voided' => 11,
    ));
}

/**
  Add foodstamp elgibile total record
*/
static public function fsEligible() 
{
    Database::getsubtotals();
    if (CoreLocal::get("fsEligible") < 0 && False) {
        CoreLocal::set("boxMsg","Foodstamp eligible amount inapplicable<P>Please void out earlier tender and apply foodstamp first");
        CoreLocal::set('boxMsgButtons', array(
            'Dismiss [clear]' => '$(\'#reginput\').val(\'CL\');submitWrapper();',
        ));
        return MiscLib::baseURL()."gui-modules/boxMsg2.php";
    } else {
        CoreLocal::set("fntlflag",1);
        Database::setglobalvalue("FntlFlag", 1);
        if (CoreLocal::get("ttlflag") != 1) {
            return self::ttl();
        } else {
            TransRecord::addRecord(array(
                'description' => 'Foodstamps Eligible',
                'trans_type' => '0',
                'trans_status' => 'D',
                'unitPrice' => MiscLib::truncate2(CoreLocal::get('fsEligible')),
                'voided' => 7,
            ));
        }

        return true;
    }
}

/**
  Add a percent discount notification
  @param $strl discount percentage
  @param $json keyed array
  @return An array see Parser::default_json()
  @deprecated
  Use discountnotify() instead. This just adds
  hard-coded percentages and PLUs that likely
  aren't applicable anywhere but the Wedge.
*/
static public function percentDiscount($strl,$json=array()) 
{
    if ($strl == 10.01) {
        $strl = 10;
    }

    if (!is_numeric($strl) || $strl > 100 || $strl < 0) {
        $json['output'] = DisplayLib::boxMsg(
            _("discount invalid"),
            '',
            false,
            DisplayLib::standardClearButton()
        );
    } else {
        if ($strl != 0) {
            TransRecord::discountnotify($strl);
        }
        $dbc = Database::tDataConnect();
        $dbc->query("update localtemptrans set percentDiscount = ".$strl);
        $chk = self::ttl();
        if ($chk !== true) {
            $json['main_frame'] = $chk;
        }
        $json['output'] = DisplayLib::lastpage();
    }

    return $json;
}

/**
  Check whether the current member has store
  charge balance available.
  @return
   1 - Yes
   0 - No

  Sets current balance in session as "balance".
  Sets available balance in session as "availBal".
*/
static public function chargeOk() 
{
    $conn = Database::pDataConnect();
    $query = "SELECT c.ChargeLimit - c.Balance AS availBal,
        c.Balance, c.ChargeOk
        FROM custdata AS c 
        WHERE c.personNum=1 AND c.CardNo = " . ((int)CoreLocal::get("memberID"));
    $table_def = $conn->table_definition('custdata');
    // 3Jan14 schema may not have been updated
    if (!isset($table_def['ChargeLimit'])) {
        $query = str_replace('c.ChargeLimit', 'c.MemDiscountLimit', $query);
    }

    $result = $conn->query($query);
    $num_rows = $conn->num_rows($result);
    $row = $conn->fetch_array($result);

    $availBal = $row["availBal"] + CoreLocal::get("memChargeTotal");
    
    CoreLocal::set("balance",$row["Balance"]);
    CoreLocal::set("availBal",number_format($availBal,2,'.',''));    
    
    return ($num_rows == 0 || !$row['ChargeOk']) ? 0 : 1;
}

/**
  Enforce age-based restrictions
  @param $required_age [int] age in years
  @param $ret [array] Parser-formatted return value
  @return [array]
   0 - boolean age-related approval required
   1 - array Parser-formatted return value
*/
public static function ageCheck($required_age, $ret)
{
    $my_url = MiscLib::baseURL();
    if (CoreLocal::get("cashierAge") < 18 && CoreLocal::get("cashierAgeOverride") != 1){
        $ret['main_frame'] = $my_url."gui-modules/adminlogin.php?class=AgeApproveAdminLogin";
        return array(true, $ret);
    }

    if (CoreLocal::get("memAge")=="") {
        CoreLocal::set("memAge",date('Ymd'));
    }
    $of_age_on_day = mktime(0, 0, 0, date('n', $ts), date('j', $ts), date('Y', $ts) + $required_age);
    $today = strtotime( date('Y-m-d') );
    if ($of_age_on_day > $today) {
        $ret['udpmsg'] = 'twoPairs';
        $ret['main_frame'] = $my_url.'gui-modules/requestInfo.php?class=UPC';
        return array(true, $ret);
    }

    return array(false, $ret);
}

public static function applyToggles($tax, $foodstamp, $discount)
{
    if (CoreLocal::get("toggletax") != 0) {
        $tax = ($tax==0) ? 1 : 0;
        CoreLocal::set("toggletax",0);
    }

    if (CoreLocal::get("togglefoodstamp") != 0){
        CoreLocal::set("togglefoodstamp",0);
        $foodstamp = ($foodstamp==0) ? 1 : 0;
    }

    if (CoreLocal::get("toggleDiscountable") == 1) {
        CoreLocal::set("toggleDiscountable",0);
        $discount = ($discount == 0) ? 1 : 0;
    }

    return array($tax, $foodstamp, $discount);
}

}

