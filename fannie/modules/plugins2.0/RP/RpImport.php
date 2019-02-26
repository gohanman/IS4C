<?php

include(__DIR__ . '/../../../config.php');
if (!class_exists('FannieAPI')) {
    include(__DIR__ . '/../../../classlib2.0/FannieAPI.php');
}

class RpImport extends FannieRESTfulPage
{
    protected $header = 'RP Import';
    protected $title = 'RP Import';

    public function changeCosts($changes)
    {
        $actual = array();
        $prodP = $this->connection->prepare("SELECT cost FROM products WHERE upc=?");
        $lcP = $this->connection->prepare("SELECT upc FROM upcLike WHERE likeCode=?");
        $upP = $this->connection->prepare("UPDATE products SET cost=? WHERE upc=?");
        foreach ($changes as $lc => $cost) {
            $upcs = $this->connection->getAllValues($lcP, array($lc));
            foreach ($upcs as $upc) {
                $current = $this->connection->getValue($prodP, array($upc));
                if ($current === false) {
                    continue; // no such product
                } elseif (abs($cost - $current) > 0.005) {
                    $actual[] = $upc;
                    echo "$lc: $upc changed from $current to $cost\n";
                    //$this->connection->execute($upP, array($cost, $upc));
                }
            }
        }
        /*
        $model = new ProdUpdateModel($this->connection);
        $model->logManyUpdates($actual, 'EDIT');
         */
    }

    /**
     * Assign active status to likecodes based on incoming
     * Excel data
     */
    public function updateActive($data)
    {
        $this->connection->query("UPDATE LikeCodeActiveMap SET inUse=0 WHERE likeCode <= 999"); 
        $upP = $this->connection->prepare("UPDATE LikeCodeActiveMap SET inUse=1 WHERE likeCode=? AND storeID=?");
        $this->connection->startTransaction();
        $map = new LikeCodeActiveMapModel($this->connection);
        foreach ($data as $lc => $info) {
            switch (strtoupper(trim($info['active']))) {
                case 'ACTIVEHD':
                    $map->likeCode($lc);
                    $map->storeID(1);
                    $map->inUse(1);
                    $map->save();
                    $map->storeID(2);
                    $map->save();
                    break;
                case 'ACTIVEH':
                    $map->likeCode($lc);
                    $map->storeID(1);
                    $map->inUse(1);
                    $map->save();
                    break;
                case 'ACTIVED':
                    $map->likeCode($lc);
                    $map->storeID(2);
                    $map->inUse(1);
                    $map->save();
                    break;
                case '0': // normal disabled status
                    break;
                default:
                    echo "Unknown status: " . $info['active'] . "\n";
            }
        }
        $this->connection->commitTransaction();
    }

    public function updateSkuMap($data)
    {

    }

    public function cliWrapper()
    {
        $out = $this->post_view();
        $out = str_replace('<tr>', '', $out);
        $out = str_replace('<td>', '', $out);
        $out = str_replace('<th>', '', $out);
        $out = str_replace('<table class="table table-bordered">', '', $out);
        $out = str_replace('</table>', '', $out);
        $out = str_replace('</tr>', "\n", $out);
        $out = str_replace("</td>", "\t", $out);
        $out = str_replace("</th>", "\t", $out);

        echo $out;
    }

    protected function post_view()
    {
        $items = array();
        foreach (explode("\n", $this->form->in) as $line) {
            if (preg_match('/(\d+)\](.)\[(.+){(.+)}(.+)\|(.+)_/', $line, $matches)) {
                list($type,$origin) = explode('\\', $matches[5]);
                $items[] = array(
                    'lc' => $matches[1],
                    'organic' => strtolower($matches[2]) == 'c' ? true : false,
                    'name' => $matches[3],
                    'price' => $matches[4],
                    'scale' => strtolower($type) == 'lb' ? true : false,
                    'origin' => $origin,
                    'vendor' => $matches[6],
                );
            }
        }

        $dbc = $this->connection;
        $lcP = $dbc->prepare('UPDATE likeCodes SET organic=?, preferredVendorID=?, origin=? WHERE likeCode=?');
        $orgP = $dbc->prepare('
            UPDATE upcLike AS u
                INNER JOIN products AS p ON u.upc=p.upc
            SET p.numflag = p.numflag | ?
            WHERE u.likeCode=?'); 
        $nonP = $dbc->prepare('
            UPDATE upcLike AS u
                INNER JOIN products AS p ON u.upc=p.upc
            SET p.numflag = p.numflag & ?
            WHERE u.likeCode=?'); 
        $orgBits = 1 << (17 - 1);
        $nonBits = 0xffffffff ^ $orgBits;

        $ret = '<table class="table table-bordered">
            <tr><th>LC</th><th>Name</th><th>Retail</th><th>Vendor</th><th>Origin</th><th>Organic</th><th>Scale</th></tr>';
        $dbc->startTransaction();
        foreach ($items as $i) {
            $ret .= sprintf('<tr><td>%d</td><td>%s</td><td>%.2f</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $i['lc'], $i['name'], $i['price'], $i['vendor'], $i['origin'],
                ($i['organic'] ? 'Yes' : 'No'),
                ($i['scale'] ? 'Yes' : 'No')
            );
            $args = array(
                $i['organic'] ? 1 : 0,
                $this->vendorToID($i['vendor']),
                $i['origin'],
                $i['lc'],
            );
            $dbc->execute($lcP, $args);
            if ($i['organic']) {
                $dbc->execute($orgP, array($orgBits, $i['lc']));
            } else {
                $dbc->execute($nonP, array($nonBits, $i['lc']));
            }
        }
        $ret .= '</table>';
        $dbc->commitTransaction();

        return $ret;
    }

    private function vendorToID($vendor)
    {
        switch (strtolower($vendor)) {
            case 'alberts':
                return 28;
            case 'cpw':
                return 25;
            case 'rdw':
                return 136;
            case 'unfi':
                return 1;
            case 'direct':
                return -2;
            default:
                return 0;
        }
    }

    protected function get_view()
    {
        return <<<HTML
<form method="post">
    <div class="form-group">
        <label>Excel Columns</label>
        <textarea name="in" rows="25" class="form-control"></textarea>
    </div>
    <div class="form-group">
        <button type="submit" class="btn btn-default btn-core">Import</button>
    </div>
</form>
HTML;
    }
}

/**
 * Locate the appropriate file, exract all its data,
 * pull out the piece that's needed, run update through the page
 * class, then finally clean up files that were created
 *
 * jxl is a java tool to more efficiently pull data out of
 * large-ish excel files
 * https://github.com/gohanman/JXL
 */
if (php_sapi_name() == 'cli' && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    $config = FannieConfig::factory();
    $settings = $config->get('PLUGIN_SETTINGS');
    $path = $settings['RpDirectory'];
    $dir = opendir($path);
    $found = false;
    while (($file=readdir($dir)) !== false) {
        if (substr($file, 0, 2) == 'RP') {
            $found = $path . $file;
        }
    }
    if ($found) {
        copy($found, '/tmp/RP.xlsm');
        $cmd = 'java -cp jxl-1.0-SNAPSHOT-jar-with-dependencies.jar coop.wholefoods.jxl.App -i /tmp/RP.xlsm -o /tmp/';
        exec($cmd);
        $dir = opendir('/tmp/');
        $otherData = array();
        while (($file=readdir($dir)) !== false) {
            if ($file == 'Comparison.tsv') {
                $fp = fopen('/tmp/Comparison.tsv', 'r');
                $input = '';
                while (!feof($fp)) {
                    $line = fgets($fp);
                    $data = explode("\t", $line);

                    $info = isset($data[107]) ? $data[107] : '';
                    if (strstr($info, ']')) {
                        $input .= $info . "\n";
                    }

                    $lc = isset($data[8]) && is_numeric($data[8]) && $data[8] ? $data[8] : false;
                    if ($lc) {
                        if (!isset($otherData[$lc])) {
                            $otherData[$lc] = array();
                        }
                        $otherData[$lc]['active'] = $data[10];
                        $otherData[$lc]['primary'] = $data[34];
                        $otherData[$lc]['alberts'] = $data[12];
                        $otherData[$lc]['cpw'] = $data[13];
                        $otherData[$lc]['rdw'] = $data[14];
                        $otherData[$lc]['unfi'] = $data[15];
                        $otherData[$lc]['rdwSKU'] = (int)$data[23];
                    }

                }
                $page = new RpImport();
                $logger = FannieLogger::factory();
                $dbc = FannieDB::get($config->get('OP_DB'));
                $page->setConfig($config);
                $page->setLogger($logger);
                $page->setConnection($dbc);
                $form = new COREPOS\common\mvc\ValueContainer();
                $form->in = $input;
                $page->setForm($form);
                $page->cliWrapper();
                $page->updateActive($otherData);
            }

            if (substr($file, -4) == '.tsv') {
                unlink('/tmp/' . $file);
            }
        }
        unlink('/tmp/RP.xlsm');
    }
    exit(0);
}

FannieDispatch::conditionalExec();

