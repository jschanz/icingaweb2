<?php
// {{{ICINGA_LICENSE_HEADER}}}
// {{{ICINGA_LICENSE_HEADER}}}

namespace Icinga\Protocol\Livestatus;

use Icinga\Application\Benchmark;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\SystemPermissionException;
use Icinga\Exception\IcingaException;

/**
 * Backend class managing handling MKI Livestatus connections
 *
 * Usage example:
 *
 * <code>
 * $lconf = new Connection((object) array(
 *     'hostname' => 'localhost',
 *     'root_dn'  => 'dc=monitoring,dc=...',
 *     'bind_dn'  => 'cn=Mangager,dc=monitoring,dc=...',
 *     'bind_pw'  => '***'
 * ));
 * </code>
 *
 * @copyright  Copyright (c) 2013 Icinga-Web Team <info@icinga.org>
 * @author     Icinga-Web Team <info@icinga.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU General Public License
 */
class Connection
{
    const TYPE_UNIX = 1;
    const TYPE_TCP  = 2;

    protected $available_tables = array(
        'hosts',               // hosts
        'services',            // services, joined with all data from hosts
        'hostgroups',          // hostgroups
        'servicegroups',       // servicegroups
        'contactgroups',       // contact groups
        'servicesbygroup',     // all services grouped by service groups
        'servicesbyhostgroup', // all services grouped by host groups
        'hostsbygroup',        // all hosts grouped by host groups
        'contacts',            // contacts
        'commands',            // defined commands
        'timeperiods',         // time period definitions (currently only name
                               // and alias)
        'downtimes',           // all scheduled host and service downtimes,
                               // joined with data from hosts and services.
        'comments',            // all host and service comments
        'log',                 // a transparent access to the nagios logfiles
                               // (include archived ones)ones
        'status',              // general performance and status information.
                               // This table contains exactly one dataset.
        'columns',             // a complete list of all tables and columns
                               // available via Livestatus, including
                               // descriptions!
        'statehist',           // 1.2.1i2 sla statistics for hosts and services,
                               // joined with data from hosts, services and log.
    );

    protected $socket_path;
    protected $socket_host;
    protected $socket_port;
    protected $socket_type;
    protected $connection;

    public function hasTable($name)
    {
        return in_array($name, $this->available_tables);
    }

    public function __construct($socket = '/var/lib/icinga/rw/live')
    {
        $this->assertPhpExtensionLoaded('sockets');
        if ($socket[0] === '/') {
            if (! is_writable($socket)) {
                throw new SystemPermissionException(
                    'Cannot write to livestatus socket "%s"',
                    $socket
                );
            }
            $this->socket_type = self::TYPE_UNIX;
            $this->socket_path = $socket;
        } else {
            if (! preg_match('~^tcp://([^:]+):(\d+)~', $socket, $m)) {
                throw new ConfigurationError(
                    'Invalid TCP socket syntax: "%s"',
                    $socket
                );
            }
            // TODO: Better config syntax checks
            $this->socket_host = $m[1];
            $this->socket_port = (int) $m[2];
            $this->socket_type = self::TYPE_TCP;
        }
    }

    /**
     * Count unlimited rows matching the query filter
     *
     * TODO: Currently hardcoded value, as the old variant was stupid
     *       Create a working variant doing this->execute(query->renderCount())...
     *
     * @param Query $query the query object
     *
     * @return int
     */
    public function count(Query $query)
    {
    return 100;
        $count = clone($query);
        $count->count();
        Benchmark::measure('Sending Livestatus Count Query');
        $data = $this->doFetch((string) $count);
        Benchmark::measure('Got Livestatus count result');
        return $data[0][0];
    }

    /**
     * Fetch a single row
     *
     * TODO: Currently based on fetchAll, that's bullshit
     *
     * @param Query $query the query object
     *
     * @return object the first result row
     */
    public function fetchRow(Query $query)
    {
        $all = $this->fetchAll($query);
        return array_shift($all);
    }

    /**
     * Fetch key/value pairs
     *
     * TODO: Currently slow, needs improvement
     *
     * @param Query $query the query object
     *
     * @return array
     */
    public function fetchPairs(Query $query)
    {
        $res = array();
        $all = $this->fetchAll($query);
        foreach ($all as $row) {
            // slow
            $keys = array_keys((array) $row);
            $res[$row->{$keys[0]}] = $row->{$keys[1]};
        }
        return $res;
    }

    /**
     * Fetch all result rows
     *
     * @param Query $query the query object
     *
     * @return array
     */
    public function fetchAll(Query $query)
    {
        Benchmark::measure('Sending Livestatus Query');
        $data = $this->doFetch((string) $query);
        Benchmark::measure('Got Livestatus Data');
        if ($query->hasColumns()) {
            $headers = $query->getColumnAliases();
        } else {
            $headers = array_shift($data);
        }
        $result = array();
        foreach ($data as $row) {
            $result_row = & $result[];
            $result_row = (object) array();
            foreach ($row as $key => $val) {
                $result_row->{$headers[$key]} = $val;
            }
        }
        if ($query->hasOrder()) {
            usort($result, array($query, 'compare'));
        }
        if ($query->hasLimit()) {
            $result = array_slice(
                $result,
                $query->getOffset(),
                $query->getLimit()
            );
        }
        Benchmark::measure('Data sorted, limits applied');

        return $result;
    }

    protected function doFetch($raw_query)
    {
        $conn = $this->getConnection();
        $this->writeToSocket($raw_query);
        $header = $this->readFromSocket(16);
        $status = (int) substr($header, 0, 3);
        $length = (int) trim(substr($header, 4));
        $body = $this->readFromSocket($length);
        if ($status !== 200) {
            throw new IcingaException(
                'Problem while reading %d bytes from livestatus: %s',
                $length,
                $body
            );
        }
        $result = json_decode($body);
        if ($result === null) {
            throw new IcingaException('Got invalid response body from livestatus');
        }

        return $result;
    }

    protected function readFromSocket($length)
    {
        $offset = 0;
        $buffer = '';

        while ($offset < $length) {
            $data = socket_read($this->connection, $length - $offset);
            if ($data === false) {
                throw new IcingaException(
                    'Failed to read from livestatus socket: %s',
                    socket_strerror(socket_last_error($this->connection))
                );
            }
            $size = strlen($data);
            $offset += $size;
            $buffer .= $data;

            if ($size === 0) {
                break;
            }
        }
        if ($offset !== $length) {
            throw new IcingaException(
                'Got only %d instead of %d bytes from livestatus socket',
                $offset,
                $length
            );
        }

        return $buffer;
    }

    protected function writeToSocket($data)
    {
        $res = socket_write($this->connection, $data);
        if ($res === false) {
            throw new IcingaException('Writing to livestatus socket failed');
        }
        return true;
    }

    /**
     * Raise an exception showing given message string and last socket error
     *
     * TODO: Find a better exception type for such errors
     *
     * @throws IcingaException
     */
    protected function socketError($msg)
    {
        throw new IcingaException(
            $msg . ': ' . socket_strerror(socket_last_error($this->connection))
        );
    }

    protected function assertPhpExtensionLoaded($name)
    {
        if (! extension_loaded($name)) {
            throw new IcingaException(
                'The extension "%s" is not loaded',
                $name
            );
        }
    }

    protected function getConnection()
    {
        if ($this->connection === null) {
            if ($this->socket_type === self::TYPE_TCP) {
                $this->establishTcpConnection();
            } else {
                $this->establishSocketConnection();
            }
        }
        return $this->connection;
    }

    protected function establishTcpConnection()
    {
        // TODO: find a bedder place for this
        if (! defined('TCP_NODELAY')) {
            define('TCP_NODELAY', 1);
        }

        $this->connection = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (! @socket_connect($this->connection, $this->socket_host, $this->socket_port)) {
            throw new IcingaException(
                'Cannot connect to livestatus TCP socket "%s:%d": %s',
                $this->socket_host,
                $this->socket_port,
                socket_strerror(socket_last_error($this->connection))
            );
        }
        socket_set_option($this->connection, SOL_TCP, TCP_NODELAY, 1);
    }

    protected function establishSocketConnection()
    {
        $this->connection = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if (! socket_connect($this->connection, $this->socket_path)) {
            throw new IcingaException(
                'Cannot connect to livestatus local socket "%s"',
                $this->socket_path
            );
        }
    }

    public function connect()
    {
        if (!$this->connection) {
            $this->getConnection();
        }

        return $this;
    }

    public function disconnect()
    {
        if ($this->connection) {
            socket_close($this->connection);
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
