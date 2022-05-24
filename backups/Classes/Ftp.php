<?php
namespace Classes;

class Ftp
{
    // private $settings = [
    //     'host' => 'diller.comportal.kz',
    //     'username' => 'ftp.diller',
    //     'password' => 'rHW81v!Sh5Hbdis'
    // ];
    private $settings = [
        'host' => 'nilefest.ftp.tools',
        'username' => 'nilefest_tests',
        'password' => 'BTT99s98ckLi'
    ];
    private $ftp;

    function __construct() {
        if(!$this->getConnection()){
            die('Connection : FAIL!');
        } else {
            $this->log('Connection : SUCCESS!');
        }
    }

    /** Check and get FTP-connection
     * @return bool - result connection
     */
    private function getConnection() {
        $this->ftp = ftp_connect($this->settings['host']);
        $loginResult = ftp_login($this->ftp, $this->settings['username'], $this->settings['password']);
        if(!$this->ftp || !$loginResult) {
            return false;
        }
        ftp_set_option($this->ftp, FTP_USEPASVADDRESS, false);
        ftp_pasv($this->ftp, true);
        return true;
    }

    /** Get list from ftp-dir with info about filename, filetype and date of last updated
     * @param string path in ftp-dir
     * @param array filters by type (dir, xml, csv...)
     * @return array
     */
    public function getList(string $path = '.', array $filters = []): array {
        $list = ftp_rawlist($this->ftp, $path);
        $list = array_map(function($elem) { return $this->convertRawInfo($elem); }, $list);
        if($filters) $list = array_filter($list, function($elem) { return in_array($elem[1], $filters); });
        return array_filter($list, function($elem) { return !in_array($elem[0], ['.', '..']); });
    }

    /** Download file from FTP
     * @param string
     * @return bool result of operation
     */
    public function download($fileLocal, $fileRemote) {
        return ftp_get($this->ftp, $fileLocal, $fileRemote, FTP_BINARY);
    }

    /** Download all files from FTP-dir
     * @param string local path for save
     * @param string remote path fro downloads
     */
    public function downloadFromDir($pathLocal, $pathRemote): void {
        $list = $this->getList($pathRemote);
        if(!is_dir($pathLocal)){
            mkdir($pathLocal);
        }
        foreach($list as $item){
            if(in_array($item[1], ['', 'dir'])) continue;
            if($this->download($pathLocal . '/' . $item[0], $pathRemote . '/' . $item[0])){
                $this->log(sprintf("Download: %s", $item[0]));
            }
        }
    }

    /** Convert Row-info from ftp_rawlist()
     * @param string info
     * @return array [filename, filetype, date of last updated]
     */
    protected function convertRawInfo($rowInfo): array {
        $splitString = array_values(array_filter(explode(' ', $rowInfo)));
        $mtime = sprintf('%s %s %s', $splitString[5], $splitString[6], $splitString[7]);
        $name = implode(' ', array_slice($splitString, 8));
        $type = explode('.', $name)[1] ?? 'dir';
        return [$name, $type, \DateTime::createFromFormat('M j H:i', $mtime)];
    }

    /** Close FTP-connection */
    public function close() {
        ftp_close($this->ftp);
    }

    /** Log output
     * @param string|array|mixed for output
     * @param string|false end-string after output msg
     */
    private function log($msg = '', $end = "\n"): void {
        print_r($msg);
        if($end) echo $end;
    }
}