<?php

include(__DIR__ . '/../../../config.php');
if (!class_exists('FannieAPI')) {
    include(__DIR__ . '/../../../classlib2.0/FannieAPI.php');
}

class RpImport extends FannieRESTfulPage
{
    protected $header = 'RP Import';
    protected $title = 'RP Import';

    public function cliWrapper()
    {
        echo $this->post_view();
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
        $lcP = $dbc->prepare('UPDATE likeCodes SET organic=?, preferredVendorID=? WHERE likeCode=?');
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

