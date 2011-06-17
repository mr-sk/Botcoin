<?php
/**
 * @author      mr-sk aka sk <sk@mr-sk.com>
 * @package     Botcoin
 * @copyright   None yo, just keep this header in place!
 *              If you find this project or code useful, please consider donating:
 *                  
 */
class Botcoin
{
    /**@#+
     * The current MtGox ticket API (GET Request, JSON Response)
     * The current bitcoincharts weighted currency prices API (GET Request, JSON Response)
     * mtGox trades for the last 3x days (typically) (GET Request, JSON Response)
     * TradeHill trades for the last x days (GET Request, JSON Response)
     * Minimum wait time for successive mtGox requests.
     * Minimum wait time for successive bitcointcharts requests.
     * ' ' ' mtGox trades API requests.
     * 
     */
    const mtgoxTicketURL    = 'https://mtgox.com/code/data/ticker.php';
    const btcchartsURL      = 'http://bitcoincharts.com/t/weighted_prices.json';
    const mtgoxTradesURL    = 'https://mtgox.com/code/data/getTrades.php';
    const thTradesURL       = 'https://api.tradehill.com/API/USD/Trades';
    const mtgoxWaitSec      = 5;   
    const bChartsWaitSec    = 900; // 15m per their site. 
    const mtgoxTradeWaitSec = 900; // 15 because we don't want to hammer their API.
    const thTradeWaitSec    = 900; // ditto.
    /*@#-*/

    const constVersion   = 0.4; // Current version - updated manually.
    const GITURL         = 'https://github.com/mr-sk/Botcoin/blob/master/README';

    /**@#+
     * Supported public commands:
     * !help      Display the help menu.
     * !currency  Display weighted currency values.
     * !ticker    Display ticker values (high, low, bid, ask, etc).
     * !trading   Displays detailed trading data for the last 3x days.
     * !set       Set a watch for a high or low price.
     * !check     Inspect each 'set' price and see if it should be triggered.
     * !clear     Removes all 'set's.
     * !show      Display (to nick) the value of the high/low lists.
     */
    const cmdHelp        = '!help';
    const cmdCurrency    = '!currency';
    const cmdTicker      = '!ticker';
    const cmdTrading     = '!trading';
    const cmdSet         = '!set';
    const cmdCheck       = '!check';
    const cmdClearSet    = '!clear';
    const cmdShowSet     = '!show';
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
    static $_btcTradingStr  = null;
    static $_thTradingStr   = null;
    
    private $_lastTickerRequest   = null;
    private $_lastCurrencyRequest = null;
    private $_lastTradingRequest  = null;
    private $_lastTHTradingRequest= null;
    
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

        setlocale(LC_MONETARY, 'en_US');

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
        $this->sendDataToChannel(sprintf("[%s v%s by sk]", __CLASS__, self::constVersion));
        $this->sendDataToChannel(sprintf("commands: %s, %s, %s, %s, %s, %s, %s",
                                         self::cmdSet,
                                         self::cmdShowSet,
                                         self::cmdCheck,
                                         self::cmdClearSet,
                                         self::cmdTicker,
                                         self::cmdTrading,
                                         self::cmdCurrency));
        $this->sendDataToChannel(sprintf('See %s for a detailed list of commands', self::GITURL));
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
            $spread = bcsub($btcSet->ticker->sell, $btcSet->ticker->buy, 6);
            
            self::$_btcTickerStr =
                sprintf("($) High:%s Low:%s Vol:%s Bid:%s Ask:%s Last:%s Spread:%s",
                        $btcSet->ticker->high,
                        $btcSet->ticker->low,
                        number_format($btcSet->ticker->vol),
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
        if ((time() > $this->_lastCurrencyRequest+self::bChartsWaitSec) || null == self::$_btcCurrencyStr)
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
     * The entry point for fetching trading data. We make two calls:
     * - MtGox trades
     * - TradeHill trades
     * Format the response and return the strings to the channel. We also do checking
     * on the time to make sure we don't hit the servers too often.
     *
     * @return  String  Error messages or trading data.
     */
    private function getTradingData()
    {
        if ((time() > $this->_lastTradingRequest+self::mtgoxTradeWaitSec) || null == self::$_btcTradingStr)
        {
            $this->sendDataToChannel($this->fetchTradingDataFromExchange('MtGox',
                                                                         self::mtgoxTradesURL,
                                                                         self::$_btcTradingStr,
                                                                        $this->_lastTradingRequest));
        }
        else
        {
            $this->sendDataToChannel(sprintf("[%ss stale] %s MtGox",
                                             (time() - $this->_lastTradingRequest),
                                              self::$_btcTradingStr));                
        }
        
        if ((time() > $this->_lastTHTradingRequest+self::thTradeWaitSec) || null == self::$_thTradingStr)
        {
            $this->sendDataToChannel($this->fetchTradingDataFromExchange('TradeHill',
                                                                         self::thTradesURL,
                                                                         self::$_thTradingStr,
                                                                         $this->_lastTHTradingRequest));            
        }
        else
        {
            $this->sendDataToChannel(sprintf("[%ss stale] %s Tradehill",
                                             (time() - $this->_lastTHTradingRequest),
                                             self::$_thTradingStr));              
        }
    }
    
    /**
     * For the provided exchange API (they happen to have the same JSON Response data)
     * we make a call and parse the data.
     *
     * @param   String  The name of the exchange.
     * @param   String  The URL of the API to call.
     * @param   String  REFERENCE The static string for caching results.
     * @param   Time    The last time we successfully called this API
     *
     * @return  String  An error message or formatted trading data.
     */
    private function fetchTradingDataFromExchange($exch, $url, &$staticStorage, &$lastTradingRequest)    
    {
        $tradeJSON = file_get_contents($url);
        if (FALSE == $tradeJSON)
        {
            return 'Error retreiving trades, try again soon';
        }
        
        $json = json_decode($tradeJSON);
        if (NULL == $json || FALSE == $json)
        {
            return 'Response is malformed';
        }
        
        $tmpStack = array();
        $internalCount = $sectionCount = $volume = $cash = 0;
        foreach($json as $trade)
        {
            if (date('Y-m-d') == date('Y-m-d', $trade->date))
            {
                $volume += $trade->amount;
                $cash   += $trade->price;
                array_push($tmpStack, $trade);
                $sectionCount++;
            }
            
            if (date('Y-m-d') == date('Y-m-d', $trade->date)
            && (date('Y-m-d') == !isset($json[$internalCount+1]->date)))
            {
                $this->computeWeightedVolumeEOD($tmpStack, 10, $prevVolume, $price);        
                $staticStorage =
                    $this->prepareTradingOutput('Todays ' . $exch,
                                                $cash,
                                                $price,
                                                $volume,
                                                $prevVolume,
                                                $sectionCount);  
            }
            $internalCount++;
        }
        
        $lastTradingRequest = time();
        return $staticStorage;
        
            
    }

    /**
     * Takes the gathered trading data and formats into a string for the user.
     *
     * @param   String  $date       A date representation.
     * @param   Float   $cash       The amount of money traded.
     * @param   Float   $price      Price of the last highest volume trade (n of 10)
     * @param   Float   $volume     The number of BTC peices traded.
     * @param   Float   $prevVolume The highest volume share of the previous 10 checked.
     * @param   int     $sectionCount A counter that acts as an internal position pointer.
     *
     * @return  String              A pretty print string for the client of trading data.
     */
    private function prepareTradingOutput($date, $cash, $price, $volume, $prevVolume, $sectionCount)
    {
        $str  = sprintf("%s | W-EOD:$%s V:%s |", $date, money_format('%i', $price), number_format($prevVolume));
        $str .= sprintf(" V:%s | $%s | ", number_format($volume), money_format('%i', $cash));
        $str .= sprintf("Avg V:%s for %s transactions\n", number_format(($volume / $sectionCount)), $sectionCount);
        
        return $str;
    }

    /**
     * Since we don't have EOD data, we'll compute it by taking the highest volume
     * trade of the LAST 10 trades for the given trading day. From that we'll use
     * the volume and price for that EOD's data.
     *
     * @param   Array   A stack of trades.
     * @param   int     Counter for trades.
     * @param   Float   REFERENCE - we'll set this value after calculating it.
     * @param   Float   REFERENCE - ditto.
     */
    private function computeWeightedVolumeEOD($tmpStack, $numberOfLastTrades, &$prevVolume, &$price)
    {
        $volume = $price = $prevVolume = 0;
        for($i=count($tmpStack)-$numberOfLastTrades; $i<count($tmpStack); $i++)
        {
            $volume = $tmpStack[$i]->amount;
            if ($volume > $prevVolume)
            {
                $prevVolume = $volume;
                $price      = $tmpStack[$i]->price;
            }
        }    
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
     * Resets both the low and high lists.
     */
    private function clearBTCWatch()
    {
        $this->_lowList  = array();
        $this->_highList = array();
        $this->sendDataToNick('Watchlist cleared.');                         
    }

    /**
     * Foreach of the 'lists' that has values, we send back a comma seperated list to the
     * request nick.
     */
    private function showBTCWatch()
    {
        if (count($this->_lowList))
        {
            $this->sendDataToNick(sprintf("Low list: %s",
                                         $this->makeStringFromList($this->_lowList)));
        }
        if (count($this->_highList))
            $this->sendDataToNick(sprintf("High list: %s",
                                         $this->makeStringFromList($this->_highList)));
    }
   
   /**
    * Method for assisting in creating a user friendly string of watch prices.
    *
    * @param    Array  $set    A multi-dimensional (2) array of high or low watch prices.
    * @return   String         A string of prices or an empty string.
    */
    private function makeStringFromList($set)
    {
        $out = array();
        foreach($set as $s)
        {
            array_push($out, $s[0]);
        }

        return (join(',', $out));
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
                        fclose($this->_socket);
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
                
                    case self::cmdTrading:
                        $this->sendDataToChannel($this->getTradingData());
                    break;
                
                    case self::cmdSet:
                        $this->setBTCWatch();
                    break;
                        
                    case self::cmdCheck:
                        $this->checkWatchLists();
                    break;
                
                    case self::cmdClearSet:
                        $this->clearBTCWatch();
                    break;
                
                    case self::cmdShowSet:
                        $this->showBTCWatch();
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
