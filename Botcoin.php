<?php
/**
 * @author      mr-sk aka sk s<k@mr-sk.com>
 * @package     Botcoin
 * @copyright   None yo, just keep this header in place!
 */
class Botcoin
{
    /**@#+
     * The current MtGox ticket API (GET Request, JSON Response)
     * The current bitcoincharts weighted currency prices API (GET Request, JSON Response)
     * Minimum wait time for successive mtGox requests.
     * Minimum wait time for successive bitcointcharts requests. 
     */
    const mtgoxTicketURL = 'https://mtgox.com/code/data/ticker.php';
    const btcchartsURL   = 'http://bitcoincharts.com/t/weighted_prices.json';
    const mtgoxWaitSec   = 5;   
    const bChartsWaitSec = 900; // 15m per their site. 
    /*@#-*/

    const constVersion   = 0.1; // Current version - updated manually.

    /**@#+
     * Supported public commands:
     * !help      Display the help menu.
     * !currency  Display weighted currency values.
     * !ticker    Display ticket values (high, low, bid, ask, etc).
     */
    const cmdHelp        = '!help';
    const cmdCurrency    = '!currency';
    const cmdTicker      = '!ticker';
    const cmdSet         = '!set';
    const cmdCheck       = '!check';
    /*@#-*/

    /**@#+
     * Supported private commands:
     * !join     Join the pre-set channel.
     * !shutdown Quit IRC / leave the server.
     * !part     Leave the channel, stay connected to server.
     */
    const cmdJoin     = '!join';
    const cmdShutdown = '!shutdown';
    const cmdPart     = '!part';
    /*@#-*/

    /**@#+
     * The static variables are used for caching purposes due to limits on API calls.
     *
     * The remaining private $_{...} variables are used throughout the class and have no
     * getters or setters, sorry.
     */
    static $_btcTickerStr   = null;
    static $_btcCurrencyStr = null;
    
    private $_lastTickerRequest   = null;
    private $_lastCurrencyRequest = null;
    
    private $_highList  = null;
    private $_lowList   = null;
    
    private $_port       = null;
    private $_host       = null;
    private $_channel    = null;
    private $_name       = null;
    private $_nick       = null;
    private $_key        = null;
    private $_owner      = null;
    private $_trigFrqSec = null;
    private $_tickFrqSec = null;
 
    private $_socket   = null;   
    private $_errorVal = null;
    private $_errorStr = null;

    private $_response        = null;
    private $_currentBTCPrice = 0.00;
    /*@#-*/

    /**
     * Class constructor. This has to be called to setup the internal instance variables.
     * @see  <Config.ini> file for options.
     * 
     * @param   Array   $configSet  An array taken from the config.ini.
     * @return  Object              A new instance of the class OR an Exception on failure.
     */
    function __construct($configSet)
    {
        $this->_port       = $configSet['port'];
        $this->_host       = $configSet['host'];
        $this->_channel    = $configSet['channel'];
        $this->_name       = $configSet['name'];
        $this->_nick       = $configSet['nick'];
        $this->_key        = $configSet['key'];
        $this->_owner      = $configSet['owner'];
        $this->_trigFrqSec = $configSet['triggerUpdateFreq'];
        $this->_tickFrqSec = $configSet['tickerUpdateFreq'];
        
        // The config.ini has default values, if they are missing we can't continue.
        // This should be more robust, example, port check between 1024 and RFC limit.
        if ('' == $this->_port)  { throw new Exception("Port not set"); }
        if ('' == $this->_host)  { throw new Exception("Host not set"); }
        if ('' == $this->_nick)  { throw new Exception("Nick not set"); }
        if ('' == $this->_name)  { throw new Exception("Name not set"); }
        if ('' == $this->_owner) { throw new Exception("Owner not set"); }
        if ('' == $this->_trigFrqSec) { throw new Exception('Trigger freq not set'); }
        if ('' == $this->_tickFrqSec) { throw new Exception('Ticket freq not set'); }
        
        $this->_highList = array();
        $this->_lowList  = array();
        
        $this->dbg("Starting ...");
    }

    /**
     * Private methods.
     */


    /**
     * Helper method for printing debug output. Data in $str will be printed to the error_log
     * file. You can set one up in your local php.ini file.
     *
     * @param   MIXED $str  Can be a string or an array.
     */
    private function dbg($str)
    {
        error_log(sprintf("[%s] %s", __CLASS__, (is_array($str))? print_r($str, true) : $str));
    }

    /**
     * Attempts to log into an IRC server using the configuration options provided to
     * __construct from <config.ini>.
     *
     * Throws an exception on failure.
     */
    private function logIntoIrc()
    {
        try
        {
            $this->_socket = fsockopen($this->_host,
                                       $this->_port,
                                       $this->_errorVal,
                                       $this->_errorStr);
        }
        catch (Exception $e)
        {
            $this->dbg(exception);
            exit();
        }
        
        $this->sendData(sprintf("USER %s 8 * : %s", $this->_nick, $this->_name));
        $this->sendData(sprintf("NICK %s", $this->_nick));
        if ('' != $this->_channel)
        {
            $this->sendData(sprintf("JOIN #%s %s",
                                    $this->_channel,
                                    ($this->_key)? $this->_key : ''));
        }
    }

    /**
     * Sends $data to the '_socket'. This is sending data to the IRC server.
     *
     * @param string $data  A string of data to be sent to the server.
     */
    private function sendData($data)
    {
        if ('' != $data)
        {
            fputs($this->_socket, sprintf("%s\r\n", $data));
            $this->dbg($data);
        }
    }

    /**
     * Prints a list of supported commands to the IRC channel.
     */
    private function generateHelpMenu()
    {
        $this->sendDataToChannel(sprintf("[%s v%s by sk]", __CLASS__, constVersion));
        $this->sendDataToChannel(sprintf('%s   - BTC ticker (MtGox)', self::cmdTicker));
        $this->sendDataToChannel(sprintf('%s - Weighted curreny value (Bitcoincharts)', self::cmdCurrency));
        $this->sendDataToChannel(sprintf('%s    - Process any triggers we have.', self::cmdCheck));
        $this->sendDataToChannel('            This can also be set in config.ini');
        $this->sendDataToChannel(sprintf('%s  <high|low> price - Set an alert at a price', self::cmdSet));
        $this->sendDataToChannel('      Example: set high 30.01 [alert when BTC >= 30.01]');
    }

    /**
     * Sends the $str to the IRC channel.
     *
     * @param string $str   The string to send to the channel.
     */
    private function sendDataToChannel($str)
    {
        $this->sendData(sprintf("PRIVMSG #%s :%s",
                                $this->_channel,
                                $str));        
    }

    /**
     * Sends $str to the LAST nick that sent a message to the channel. In a moderate/high chat
     * channel this implementation is flawed, because message could be send to the wrong nick.
     * TODO:
     *     Fix message to track the nick that issue the command, NOT just the last nick that
     *     issues a message to the channel.
     *
     * @param string $str   The string to send to the nick.
     */
    private function sendDataToNick($str)
    {
        // Nicks are prefixed w/a !, so remove it.
        $nick = substr($this->_response[0], 0, strpos($this->_response[0], '!'));
        $this->sendData(sprintf("PRIVMSG %s :%s",
                                $nick,
                                $str));           
    }
    
    /**
     * First we check if we've made a request within in the wait limit, and if so,
     * we return stale data. We also check if the static variable $_btcTickerStr is
     * null (this will only happen on the first run). If its null, we are cool to make
     * a call to MtGox to get the current ticker.
     *
     * Attempts to query the MtGox API. If the Request fails, we print an error message
     * and do NOT update the _lastTickerRequest time.
     *
     * If the Request succeeds, we format a message for the channel, update the lastTickerRequest
     * time and also set the current price of a BTC to the last sale price. This price is used
     * to check limits/triggers against.
     *
     * @return string   Return a ticker formatted string, or an error message on failure.
     */
    private function getBitCoinData()
    {
        // Enforce that we can't hit the mtGox server more than once every 5 seconds.
        if ((time() > $this->_lastTickerRequest+self::mtgoxWaitSec) || null == self::$_btcTickerStr)
        {
            $btcJSON = file_get_contents(self::mtgoxTicketURL);
            if (FALSE == $btcJSON)
            {
                return 'Error retreiving ticker, try again soon.';
            }
            
            $btcSet = json_decode($btcJSON);
            $spread = ((float) $btcSet->ticker->sell) - ((float) $btcSet->ticker->buy);
            
            self::$_btcTickerStr =
                sprintf("High:%s Low:%s Vol:%s Bid:%s Ask:%s Last:%s Spread:%s",
                        $btcSet->ticker->high,
                        $btcSet->ticker->low,
                        $btcSet->ticker->vol,
                        $btcSet->ticker->buy,
                        $btcSet->ticker->sell,
                        $btcSet->ticker->last,
                        $spread);
            $this->_lastTickerRequest = time();

            // Set the current price to the last sale price. 
            $this->_currentBTCPrice = $btcSet->ticker->last;
            return self::$_btcTickerStr;
        }
        
        return sprintf("[%ss stale] %s", (time() - $this->_lastTickerRequest), self::$_btcTickerStr);
    }
    
    /**
     * First we check if we've attempted to make a request w/in the time limit for Bitcoincharts.
     * If so, return stale data.
     *
     * If we haven't made a request, or $_btcCurrencyStr is null (only on first run), we attempt
     * to make a GET Requst to the bitcoincharts API. If we don't get a respone we return an error
     * message.
     *
     * If we get a response, we format a currency string for the channel and update the
     * _lastCurrencyRequest.
     *
     * @return string   A currency string or an error message on failure.
     */
    private function getCurrencyData()
    {
        // Enforce that we can't hit the Bitcoins server more than once every 15 minutes.
        if ((time() > $this->_lastCurrencyRequest+900) || null == self::$_btcCurrencyStr)
        {
            $currencyJSON = file_get_contents(self::btcchartsURL);
            if (FALSE == $currencyJSON)
            {
                return 'Error retreiving currency, try again soon.';
            }
            
            $cSet = json_decode($currencyJSON);
            self::$_btcCurrencyStr = sprintf("USD 24h:%s 7d:%s 30d:%s",
                                             $cSet->USD->{'24h'},
                                             $cSet->USD->{'7d'},
                                             $cSet->USD->{'30d'});
            $this->_lastCurrencyRequest = time();
            return self::$_btcCurrencyStr;
        }
        
        return sprintf("[%ss stale] %s", (time() - $this->_lastCurrencyRequest), self::$_btcCurrencyStr);
    }
    
    /**
     * This method allows us to set price alerts when the BTC value moves. For example we
     * can set an alert to be issue when the price passes a high of 15.01. If/When the BTC value
     * is equal to or greater than 15.01 the alert would have been met.
     *
     * We support two types of alerts, "high" and "low".
     * TODO:
     *  We don't need the client to specify the types, we can infer what they want. In other words,
     *  if the current BTC price is 15.01 and they issue an alert for 14.95, we can assume the user
     *  wants to notified when the price is <= 14.95. The same could be said for setting an alert
     *  at 15.25. The user would want an alert when the price is >= 15.01.
     *
     * @See "sendDataToNick" for bug.
     *
     * @return string  A success message to the nick or an error message to nick.
     */
    private function setBTCWatch()
    {
        $watchPrice = (float) $this->_response[5];

        if ($watchPrice <= 0.0)
        {
            $this->sendDataToNick('Invalid request, see !help.');            
            return; 
        }
        
        if ('high' == $this->_response[4] && $watchPrice > $this->_currentBTCPrice)
        {
            $this->addToWatchList(&$this->_highList, $watchPrice);
        }        

        if ('low' == $this->_response[4] && $watchPrice < $this->_currentBTCPrice)
        {
            $this->addToWatchList(&$this->_lowList, $watchPrice);
        }   

        $this->sendDataToNick('Trigger set.');                 
    }
    
    /**
     * Adds the provided price in $watchPrice to the provded $list.
     * @See "sendDataToNick" for bug.
     * 
     * @param REFERENCE array $list         A price list to be amended if condition is met.
     * @param           string $watchPrice  The watch price to set.
     *
     * @return Nothing on success (An updated $list) or a message to the nick on failure.
     */
    private function addToWatchList(&$list, $watchPrice)
    {
        foreach($list as $priceWatch)
        {
            if ($priceWatch[0] == $watchPrice)
            {
                $this->sendDataToNick(sprintf('Watch exists at: %s', $priceWatch[0]));
                return;
            }
        }
        
        array_push($list, array($watchPrice, $this->_currentBTCPrice));        
    }
    
    /**
     * Iterate through both "high" and "low" watchlists sending out alerts whenever
     * the watch criteria is met.
     *
     * TODO:
     *  Enough duplicate code here that it should be consolidated.
     */
    private function checkWatchLists()
    {
        $keepSet = array();
        foreach($this->_highList as $priceWatch)
        {
            $this->dbg(sprintf("Is %s >= %s",
                               $this->_currentBTCPrice,
                               $priceWatch[0]));
            if ($this->_currentBTCPrice >= $priceWatch[0])
            {
                $this->sendDataToChannel(sprintf('Current price: %s triggered watch: %s',
                                                 $this->_currentBTCPrice,
                                                 $priceWatch[0]));
            }
            else{ array_push($keepSet, $priceWatch); } // Only keep non-triggered entries.
        }
        $this->_highList = $keepSet;
        
        $keepSet = array();
        foreach($this->_lowList as $priceWatch)
        {
            $this->dbg(sprintf("Is %s <= %s",
                               $this->_currentBTCPrice,
                               $priceWatch[0]));
            if ($this->_currentBTCPrice <= $priceWatch[0])
            {
                $this->sendDataToChannel(sprintf('Current price: %s triggered watch: %s',
                                                 $this->_currentBTCPrice,
                                                 $priceWatch[0]));
            }
            else{ array_push($keepSet, $priceWatch); } // Only keep non-triggered entries.
        }
        $this->_lowList = $keepSet;        
    }
    
    /**
     * Public methods.
     */
    
    
    /**
     * Our "event loop" method. Once constructing the class object, calling ->run() will
     * keep the bot running until 1) a shutdown is issued 2) its interupted / ctrl-c, etc
     * 3) it loses connectivity.
     */
    public function run()
    {
        $this->logIntoIrc();
        $this->getBitCoinData();
        $mainClockInSeconds = time();
        
        do
        {
            $recvBuffer = trim(fgets($this->_socket, 256));
            echo nl2br($recvBuffer);
            flush();
            
            $this->_response = explode(' ', $recvBuffer);
            if ($this->_response[0] == 'PING')
            {
                $this->sendData(sprintf("PONG %s", $this->_response[1]));            
            }
            
            $this->_response[0] = ltrim($this->_response[0], ':');
            $this->_response[3] = ltrim($this->_response[3], ':');
            
            // These commands are from owner.
            if ($this->_response[0] == $this->_owner &&
                $this->_response[1] == 'PRIVMSG')
            {
                switch($this->_response[3])
                {
                    case self::cmdShutdown:
                        $this->sendData(sprintf("QUIT"));                        
                    break;
                    
                    case self::cmdJoin:
                        $this->sendData(sprintf("JOIN #%s %s",
                                        $this->_channel,
                                        ($this->_key)? $this->_key : ''));                        
                    break;
                
                    case self::cmdPart:
                        $this->sendData(sprintf("PART #%s", $this->_channel));
                    break;
                }
            }
            
            // These commands are from channel.
            if ($this->_response[2] == '#'.$this->_channel && 
                $this->_response[1] == 'PRIVMSG')
            {
                switch($this->_response[3])
                {
                    case self::cmdHelp:
                        $this->sendDataToChannel($this->generateHelpMenu());
                    break;
                    
                    case self::cmdTicker:
                        $this->sendDataToChannel($this->getBitCoinData());
                    break;
                
                    case self::cmdCurrency:
                        $this->sendDataToChannel($this->getCurrencyData());
                    break;
                
                    case self::cmdSet:
                        $this->setBTCWatch();
                    break;
                        
                    case self::cmdCheck:
                        $this->checkWatchLists();
                    break;
                }
            }

            // We don't want to spam the channel, but we do want to keep our information up-to-date.
            // Run these, but don't broadcast results to channel.
            if ( (time() > $mainClockInSeconds + $this->_tickFrqSec) &&
                  time() > $this->_lastTickerRequest+self::mtgoxWaitSec )
            {
                $this->getBitCoinData();                 
            }
            if (time() > $mainClockInSeconds + $this->_trigFrqSec)
            {         
                $this->checkWatchLists();
            }

        } while (true);
    }
}

?>