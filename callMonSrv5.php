#!/usr/bin/php

<?php
define("VER","5.0.2");

require_once('./assets/php/ESL.php');

define("SHOW_EV",	true);

// define ("CALL_MON_PORT", 9999);

define("DBG_LEV",	1);

require_once("./assets/php/config.php");
require_once("./assets/php/fdl.php");
require_once("./assets/php/classes/mySqliClass.php");


define("CGREEN",	"#23ff23");
define("CGREY",		"#c0c0c0");
define("CYELLOW",	"#ffff00");
define("CRED",		"#ff0000");
define("CBLUE",		"#76c0ff");
define("CORANGE",	"#ff9800");



define("EVT_REQ", "CHANNEL_ANSWER CHANNEL_BRIDGE CHANNEL_CREATE CHANNEL_HANGUP CHANNEL_HOLD CHANNEL_ORIGINATE");

function dbg($lev, $s) {
	if ($lev < DBG_LEV)	return;
	if (is_array($s))		
		print_r ($s);
	else					
		echo $s;
	echo "\n";
}

class CallMonSrv {
	private $dtCalls = [];
	private $opCalls = [];
	private $inCalls = [];
	private $odCalls = [];
	private $esl = null;
	private $eslOut = null;
	
	private $opExt = null;
	private $tOld = "";	
	
	
	public function mainLoop() {

		
		if (!$this->constLoad())					return(false);
		if (!$this->opExtSet())						return(false);
		
		// $spyExt = $this->getSpyExt();
		// dbg(1,"Spy Extention : $spyExt");

		// FREESWITCH ESL

		$this->esl = new eslConnection(FS_HOST, FS_PORT, FS_PWD);
		$this->esl->events("json", EVT_REQ);

		$this->eslOut = new eslConnection(FS_HOST, FS_PORT, FS_PWD);

		
		// webSock for callMon4.hmtl


		$host = 'localhost'; //host
		$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
		socket_bind($socket, 0, CALL_MON_PORT);
		socket_listen($socket);
		$this->clients = array($socket);

		while(true) {
			
			$kp = "";
		    if($this->non_block_read(STDIN, $kp)) {
		        $this->keyPressed($kp);
		    }
			
			$this->doHeartbeat();

			$changed = $this->clients;
			socket_select($changed, $null, $null, 0, 10);

			if (in_array($socket, $changed)) {		// NEW CONNECION
				dbg(1,"socket change");
				$socket_new = socket_accept($socket); 
				$this->clients[] = $socket_new; 
				$header = socket_read($socket_new, 1024); 
				
				$this->perform_handshaking($header, $socket_new, $host, CALL_MON_PORT);
				
				socket_getpeername($socket_new, $ip); 		$this->broadcast(["type" => "system", "message"=>$ip." Connected"]);
				echo "$ip connected\n";
				
				echo "sending pars\n";
				$pars = [
					"type"		=>	"pars"
				,	"spyPwd"	=>	SPY_PWD
				,	"spyExt"	=>	$this->getSpyExt()
				];
				
				print_r($pars);
				echo "\n----------------------------------\n";
				
				$msgMasked = $this->mask(json_encode($pars));
				@socket_write($socket_new,$msgMasked,strlen($msgMasked));
				
				
				$found_socket = array_search($socket, $changed);
				unset($changed[$found_socket]); 
			}
			
			foreach ($changed as $changed_socket) {	


				
				// Handle received commants
				while(socket_recv($changed_socket, $buf, 1024, 0) >= 1) {

					$txtIn = $this->unmask($buf); 
					//echo "received : $txtIn\n";
					$msgIn = json_decode($txtIn, true); 
					dbg(1, "============= RECEIVED COMMAND FROM callMon ====================");
					print_r($msgIn);
					// dbg(1,$msgIn);
					// handle message somehow .. todo!
					switch($msgIn["msg"]) {
						case "HANGUP" :
							$d = $this->doApi("uuid_kill", $msgIn["uid"]);
							break;

						case "DO_TEST" :

							$pars= "{origination_uuid=DDDD_99b157aa-cab1-4900-bdff-7b32af98793d,opForDet=1,opExt=1000}"
								. "sofia/gateway/messagenet/0681157710 "
								. "&bridge({origination_uuid=CCCC_99b157aa-cab1-4900-bdff-7b32af98793d,opForDet=1,opExt=1000}user/1000)";
							$d = $this->doApi("originate", $pars);
							break;
							
							
						case "SPY" : 
							$pars = "{sip_secure_media=true}"
									."user/" . $msgIn["ext"] 
									. " &eavesdrop(" . $msgIn["uid"] . ")";
							$e = $this->doApi("originate", $pars);
							break;
    					
    					case "OP_FOR_DETT" :
							
							$my = new mySqliDb(T3_SRV, T3_USR, T3_PWD, T3_DB);
							$rows = $my->myGetRows("SELECT * FROM trunk WHERE ACTIVE = 1 LIMIT 1");
							if ($rows === -1)	return(basicErr($this->my->getLastErr()));
							if ($rows === 0)	return(basicErr("No trunk found!"));
							$trunk = $rows[0]["trunkStr"];
							
							// Open Call out Immediately

							$this->sendMsg([
								"evtDescr"	=>	"Operatore chiama per Dett"
							,	"uid"		=>	"DDDD_" . $msgIn["uid"]
							,	"org"		=>	"Operatore"
							,	"orgDescr"	=>	"Chiamata per Detenuto"
							,	"dst"		=>	$msgIn["numReq"] 
							,	"dstDescr"	=>	"Chiamata via Op"
							,	"bgcol"		=>	CORANGE
							,	"blink"		=>	0
							,	"hangup"	=>	0
							,	"record"	=>	0
							]);		

							
							$pars = "opForDet=1"
								. ",origUid=" . $msgIn["uid"] 
								. ",opExt=" . $msgIn["opExt"];
							// bgapi originate {opForDet=1,origUid=cce69c7e-3381-4b8b-9395-820c9b01467b}sofia/gateway/messagenet//0692928424&bridge(/user/1000)
							$orx = "origination_uuid=" . "DDDD_" . $msgIn["uid"];
							$oro = "origination_uuid=" . "CCCC_" . $msgIn["uid"];

							$pars = "{" . $orx . "," . $pars . "}" . $trunk . $msgIn["numReq"] 
								. " &bridge({" . $oro . "," . $pars . "}user/" . $msgIn["opExt"] . ")";

							$d = $this->doApi("originate", $pars);
							break;

						case "OP_TRANS_TO_DET" : 
						
							$uid = $msgIn["uid"];
							$d = $this->doApi("uuid_transfer", "$uid park inline");
							$d = $this->doApi("uuid_transfer", "DDDD_$uid park inline");
							$d = $this->doApi("uuid_bridge", "$uid DDDD_$uid");
							break;
							
    					

						default :
							dbg(1,"Unknown command: " . $msgIn["msg"]);
						    break;
					}
					
					break 2; 
				}
				
				$buf = @socket_read($changed_socket, 1024, PHP_NORMAL_READ);
				if ($buf === false) { 
					$found_socket = array_search($changed_socket, $this->clients);
					socket_getpeername($changed_socket, $ip);
					unset($this->clients[$found_socket]);
					$this->broadcast(["type" => "system", "message"=>$ip." disconnected"]);
					echo "$ip disconnected\n";
				}
			}
			
			
			$e = $this->esl->recvEventTimed(20);
			if ($e) {

				echo "\ngot event\n";
				$data = json_decode($e->serialize("json"),true);
				if(sizeof($data)>0) {
					$evDett = [ 
						"uid"	=>	getVal($data, "Unique-ID")
					,	"cid"	=>	getVal($data, "Channel-Call-UUID")
					,	"ext"	=>	getVal($data, "Caller-Username")
					,	"stt"	=>	getVal($data, "Channel-State")
					,	"num"	=>	getVal($data, "Caller-Destination-Number")
					,	"evt"	=>	getVal($data, "Event-Name")
					,	"ans"	=>	getVal($data, "Answer-State")
					,	"oid"	=>	getVal($data, "Other-Leg-Unique-ID")
					,	"uia"	=>	getVal($data, "variable_UUIDLegA")
					,	"det"	=>	getVal($data, "variable_dettId")
					,	"int"	=>	getVal($data, "variable_caller")
					,	"opa"	=>	getVal($data, "variable_viaOP")
					,	"ofd"	=>	getVal($data, "variable_opForDet")
					,	"cli"	=>	getVal($data, "variable_callId")
					,	"rid"	=>	getVal($data, "variable_origUid")
					,	"opx"	=>	getVal($data, "variable_opExt")
					];
					$this->eventHandle($evDett);
				}
			}
		}		
	}
	
	protected function doHeartbeat() {
		$tNow = gmdate("d/m/Y H:i:s");
		if($tNow ==$this->tOld ) 	return;
		
		// echo "$tNow\n";
		$this->tOld = $tNow;
		
		$this->broadcast([
			"type"	=>	"heartbeat"
		,	"time"	=>	getTimeInItaly()
		,	"ts"	=>	time()
		]);
	}

	
	protected function eventHandle($ed){

		
		if(SHOW_EV) $this->evtDettDump($ed);		
		// DET DIRECT

		// return;
		
		if ($ed["evt"]=="CHANNEL_HANGUP" && $ed["det"]!="" && $ed["uia"] != "") {
			$this->detDirectHangsup($ed);
			$this->simpleHangup($ed);
			return;
		}
		
		if($ed["evt"]=="CHANNEL_ANSWER" && $ed["det"]!="" && $ed["uia"]!="" && $ed["opa"]=="" && !in_array($ed["uia"], $this->odCalls) && !in_array($ed["uia"], $this->dtCalls))  {
			$this->detDirectAnswered($ed);
			return;
		}
	
		if($ed["evt"]=="CHANNEL_CREATE" && $ed["det"]!="" && $ed["opa"]=="" && $ed["uia"]!="" &&  !in_array($ed["num"], $this->opExt )) {
			$this->detDirectDials($ed); 
			return;
		}

		if($ed["evt"]=="CHANNEL_HANGUP" && $ed["det"]=="" && $ed["num"]==SCRIPT_EXT) {
			$this->detDirectHangupEmpty($ed);
			$this->simpleHangup($ed);
			return;
		}
		
		if($ed["evt"]=="CHANNEL_CREATE" && $ed["det"]=="" &&  $ed["num"]==SCRIPT_EXT ) {
			$this->detDirectLifts($ed); 
			return;
		}

					
		// INCOMING			

		if($ed["evt"]=="CHANNEL_CREATE" && $ed["det"]=="" && $ed["uia"]==""  && $ed["opa"]="" && in_array($ed["num"], $this->opExt)) {
			$this->incommingRing($ed);
			return;
		}
		
		if ($ed["evt"]=="CHANNEL_ANSWER" && $ed["oid"] !="" && array_key_exists($ed["cid"], $this->inCalls))  {
			$this->incommingOpAnswers($ed);
			return;
		}
		
		if ($ed["evt"]=="CHANNEL_HANGUP" && in_array($ed["num"], $this->opExt) && array_key_exists($ed["cid"], $this->inCalls))  {
			$this->incommingOpHangsup($ed);
			$this->simpleHangup($ed);
			return;
		}

		if ($ed["evt"]=="CHANNEL_HOLD" && in_array($ed["num"], $this->opExt)  && array_key_exists($ed["cid"], $this->inCalls)) {
			$this->incommingOpHolds($ed);
			return;
		}

		
		if($ed["evt"]=="CHANNEL_ORIGINATE" &&  in_array($ed["ext"], $this->opExt) && $this->isDetExt($ed["num"])) {
			$this->incommingOpCallsDet($ed);
			return;
		}
		
		if($ed["evt"]=="CHANNEL_ANSWER"	&& in_array($ed["ext"], $this->opExt) && $this->isDetExt($ed["num"]) && $ed["oid"]!="") {
			$this->incommingOpDetAnswers($ed);
			return;
		}
		
		if($ed["evt"]=="CHANNEL_BRIDGE" && array_key_exists($ed["uid"], $this->inCalls)  && array_key_exists($ed["oid"], $this->odCalls)) {
			$this->incommingOpTransfersToDet($ed);
			return;
		}
		// DET THROUGH OP
		
		if($ed["evt"]=="CHANNEL_ORIGINATE" &&  $ed["opa"]!="") {
			$this->dettThruPO($ed);
			return;
		}

		
		if($ed["evt"]=="CHANNEL_ANSWER"  && $ed["opa"]!=""  && $ed["ofd"]=="" && array_key_exists($ed["uia"],$this->odCalls))  {
			$this->dettThruPOAnswered($ed);
			return;
		}

		if($ed["evt"]=="CHANNEL_ORIGINATE" && substr($ed["uid"],0,5)=="CCCC_" ) {
			$this->dettThruPOOriginate($ed);
		//		EVT:CHANNEL_ORIGINATE det: uid:2d6a1ee5-39d6-48bf-a478-d3ba23645730 uia: cid:2d6a1ee5-39d6-48bf-a478-d3ba23645730 oid: stt:CS_INIT ext: num:0692928424 int: opa: ofd:1 cli: rid:xxxx
			return;	
		}

		if($ed["evt"]=="CHANNEL_ANSWER"  && substr($ed["uid"],0,5)=="DDDD_" ) {
			$this->dettThruPOConnected($ed);
			return;
			// EVT:CHANNEL_ANSWER det: uid:2d6a1ee5-39d6-48bf-a478-d3ba23645730 uia: cid:2d6a1ee5-39d6-48bf-a478-d3ba23645730 oid: stt:CS_CONSUME_MEDIA ext: num:0692928424 int: opa: ofd:1 cli: rid:xxxx
		}

		if($ed["evt"]=="CHANNEL_BRIDGE"  && substr($ed["oid"],0,5)=="DDDD_" ) {
			$this->dettThruTransfered($ed);
			return;
			// EVT:CHANNEL_ANSWER det: uid:2d6a1ee5-39d6-48bf-a478-d3ba23645730 uia: cid:2d6a1ee5-39d6-48bf-a478-d3ba23645730 oid: stt:CS_CONSUME_MEDIA ext: num:0692928424 int: opa: ofd:1 cli: rid:xxxx
		}

		
		if($ed["evt"]=="CHANNEL_HANGUP"  && substr($ed["uid"],0,5)=="DDDD_" ) {
			$this->dettThruPOTerminate($ed);
			return;
			// EVT:CHANNEL_ANSWER det: uid:2d6a1ee5-39d6-48bf-a478-d3ba23645730 uia: cid:2d6a1ee5-39d6-48bf-a478-d3ba23645730 oid: stt:CS_CONSUME_MEDIA ext: num:0692928424 int: opa: ofd:1 cli: rid:xxxx
		}
		


		
		if($ed["evt"]=="CHANNEL_HANGUP"  && $ed["ofd"]!="" ) {
		// evt:CHANNEL_HANGUP det: uid:a6999c9c-f219-4dd1-a72b-2b9c9d1be86b uia: cid:a6999c9c-f219-4dd1-a72b-2b9c9d1be86b oid:852f9a20-54c3-4296-9a26-c65a4eadba3d stt:CS_EXECUTE ext: num:0692928424 int: opa: ofd:1 cli:
			$this->simpleHangup($ed);
			
		}
			
		
	}	
	
	///////////////////////////////////////////////////////// EVT : DET DIRECT

	protected function detDirectLifts($ed) {
		$this->sendMsg([
			"evtDescr"	=>	"Detenuti alza cornetta"
		,	"tmbeg"		=>	gmdate("H:i:s")
		,	"uid"		=>	$ed["uid"]
		,	"org"		=>	"Int:" . $ed["ext"]
		,	"orgDescr"	=>	"Interno detenuti sollevato"
		,	"dst"		=>	""
		,	"dstDescr"	=>	""
		,	"bgcol"		=>	CYELLOW
		,	"blink"		=>	0
		,	"hangup"	=>	0
		,	"ctStart"	=>	time()
		]);		
	}

	protected function detDirectHangupEmpty($ed) {
		
		$this->sendMsg([
			"evtDescr"	=>	"Detenuto Termina Diretta"
		,	"uid"		=>	$ed["uid"]
		,	"bgcol"		=>	CGREY
		,	"blink"		=>	0
		,	"hangup"	=>	1
		,	"ctStart"	=>	0
		]);	
	}	
	
	protected function detDirectDials($ed) {

		$cd = $this->getDetCallDetails($ed["uia"]);
		if($cd===false)
			$cd = [
				$cd["dstDescr"] = "**NUMERO NON TROVATO"
			]; 
		
		$dtCall = [
			"dettUID"	=>	$ed["uia"]
		,	"dettId"	=>	$ed["det"]
		,	"opExt"		=>	""
		,	"detExt"	=>	$ed["int"]
		,	"bnum"		=>	$ed["num"]
		,	"secsGrace"	=>	getVal($cd,"secsGrace",0)
		,	"secsMax"	=>	getVal($cd,"secsMax",0)
		];

		$this->dtCalls[$ed["uia"]] = $dtCall;

		$this->sendMsg([
			"evtDescr"	=>	"Detenuto Chiama"
		,	"uid"		=>	$ed["uia"]
		,	"org"		=>	getVal($cd,"org")
		,	"orgDescr"	=>	getVal($cd,"orgDescr")
		,	"dst"		=>	getVal($cd,"dst")
		,	"dstDescr"	=>	getVal($cd,"dstDescr")
		,	"bgcol"		=>	CYELLOW
		,	"blink"		=>	0
		,	"hangup"	=>	0
		,	"record"	=>	1
		,	"secsGrace"	=>	getVal($cd,"secsGrace",0)
		,	"secsMax"	=>	getVal($cd,"secsMax",0)
		]);						
	}
	
	protected function detDirectAnswered($ed) {

		$this->dtCalls[$ed["uia"]]["dieTime"] = time() + intval($this->dtCalls[$ed["uia"]]["secsMax"]) + intval($this->dtCalls[$ed["uia"]]["secsGrace"]);
		$this->dtCalls[$ed["uia"]]["warnTime"] = intval($this->dtCalls[$ed["uia"]]["dieTime"]) - 30;

		$this->sendMsg([
			"evtDescr"	=>	"Detenuto Collegato"
		,	"uid"		=>	$ed["uia"]
		,	"tmbeg"		=>	gmdate("H:i:s")
		,	"ctStart"	=>	time() + intval($this->dtCalls[$ed["uia"]]["secsGrace"])
		,	"bgcol"		=>	CGREEN
		,	"blink"		=>	0
		,	"hangup"	=>	0
		]);								
	}
	
	protected function detDirectHangsup($ed) {
		
		unset($this->dtCalls[$ed["uia"]]);
		
		$this->sendMsg([
			"evtDescr"	=>	"Detenuto Termina Diretta"
		,	"uid"		=>	$ed["uia"]
		,	"bgcol"		=>	CGREY
		,	"blink"		=>	0
		,	"hangup"	=>	1
		]);	
	}

	///////////////////////////////////////////////////////// EVT : INCOMING FROM EXT

	protected function incommingRing($ed) {
		$inCall = [
			"callUID"	=>	$ed["cid"]
		,	"bnum"		=>	$ed["ext"]		// CALLER
		,	"opExt"		=>	$ed["num"]
		];	
				
		$this->inCalls[$ed["cid"]] = $inCall;

		$this->sendMsg([
			"evtDescr"	=>	"Chiamata entrante ad operatore"
		,	"uid"		=>	$ed["cid"]
		,	"org"		=>	$ed["ext"]
		,	"orgDescr"	=>	"Chiamata Entrante"
		,	"dst"		=>	$ed["num"]
		,	"dstDescr"	=>	"Posto Operatore"
		,	"bgcol"		=>	CYELLOW
		,	"blink"		=>	1
		,	"hangup"	=>	0
		,	"ctStart"	=>	time()
		]);
	}
	
	protected function incommingOpAnswers($ed) {
		$this->sendMsg([
			"evtDescr"	=>	"Operatore Risponde a chiamata Entrante"
		,	"tmbeg"		=>	gmdate("H:i:s")
		,	"ctStart"	=>	time()
		,	"uid"		=>	$ed["cid"]
		,	"bgcol"		=>	CGREEN
		,	"blink"		=>	0
		,	"hangup"	=>	0
		]);
	}
	
	protected function incommingOpHolds($ed) {
		$this->sendMsg([
			"evtDescr"	=>	"PO mette esterno in attesa"
		,	"uid"		=>	$ed["cid"]
		,	"bgcol"		=>	CBLUE
		,	"blink"		=>	0
		,	"hangup"	=>	0
		,	"record"	=>	0
		]);				
	}
	
	protected function incommingOpCallsDet($ed) {

		

		$this->odCalls[$ed["uid"]] = [
			"callUID"	=>	$ed["cid"]
		,	"bnum"		=>	$ed["num"]		// CALLER
		,	"opExt"		=>	$ed["ext"]
		,	"dialedNum"	=>	$dialedNum
		,	"descr"		=>	$descr
		,	"callTip"	=>	$callTip
		,	"secsMax"	=>	$secsMax
		,	"record"	=>	$record
		];
		
		$this->sendMsg([
			"evtDescr"	=>	"PO Chiama Interno Detenuti"
		,	"uid"		=>	$ed["uid"]
		,	"org"		=>	"P.O. " . $ed["ext"]
		,	"orgDescr"	=>	"Posto Operatore"
		,	"dst"		=>	$ed["num"]
		,	"dstDescr"	=>	"Interno Detenuti " . $ed["num"]
		,	"bgcol"		=>	CYELLOW
		,	"blink"		=>	1
		,	"hangup"	=>	0
		,	"record"	=>	0
		]);			
		
	}	
	
	protected function incommingOpHangsup($ed) {
	
		unset($this->inCalls[$ed["uid"]]);

		$this->sendMsg([
			"evtDescr"	=>	"Operatore Termina Entrante"
		,	"uid"		=>	$ed["cid"]
		,	"bgcol"		=>	CGREY
		,	"blink"		=>	0
		,	"hangup"	=>	1
		,	"ctStart"	=>	0
		]);
	}

	protected function incommingOpDetAnswers($ed) {
		$this->sendMsg([
			"evtDescr"	=>	"Detenuto risponde a PO"
		,	"uid"		=>	$ed["uid"]
		,	"bgcol"		=>	CGREEN
		,	"blink"		=>	0
		,	"hangup"	=>	0
		,	"ctStart"	=>	time()
		]);		
	}
	
	protected function incommingOpTransfersToDet($ed) {
		
		$this->sendMsg([
			"evtDescr"	=>	"PO trasferisce a Det"
		,	"uid"		=>	$ed["oid"]
		,	"org"		=>	$this->inCalls[$ed["uid"]]["bnum"]
		,	"orgDescr"	=>	"Chiamata da: " .$this->inCalls[$ed["uid"]]["bnum"]
		,	"bgcol"		=>	CGREEN
		,	"blink"		=>	0
		,	"hangup"	=>	0
		,	"record"	=>	1
		,	"ctStart"	=>	time()
		]);			
		
		$recFile = REC_PATH . $ed["oid"] . ".wav";
		dbg(1,"Recording to: " . $recFile);
		$d = $this->doApi("uuid_record",$ed["oid"] . " start " . $recFile);
	}
	
	
	protected function simpleHangup($ed) {
		$this->sendMsg([
			"evtDescr"	=>	"Hangup Generico"
		,	"uid"		=>	$ed["uid"]
		,	"bgcol"		=>	CGREY
		,	"blink"		=>	0
		,	"hangup"	=>	1
		,	"ctStart"	=>	0
		]);		

		if ($ed["oid"]!="" && substr($ed["oid"],0,5)!="DDDD_") {
			$this->sendMsg([
				"evtDescr"	=>	"Hangup Generico"
			,	"uid"		=>	$ed["oid"]
			,	"bgcol"		=>	CGREY
			,	"blink"		=>	0
			,	"hangup"	=>	1
			,	"ctStart"	=>	0
			]);		
		}

		if (array_key_exists($ed["uid"], $this->dtCalls))	unset($this->dtCalls[$ed["uid"]]);
		if (array_key_exists($ed["uid"], $this->opCalls))	unset($this->opCalls[$ed["uid"]]);
		if (array_key_exists($ed["uid"], $this->inCalls))	unset($this->inCalls[$ed["uid"]]);
		if (array_key_exists($ed["uid"], $this->odCalls))	unset($this->odCalls[$ed["uid"]]);

		if (array_key_exists($ed["cid"], $this->dtCalls))	unset($this->dtCalls[$ed["cid"]]);
		if (array_key_exists($ed["cid"], $this->opCalls))	unset($this->opCalls[$ed["cid"]]);
		if (array_key_exists($ed["cid"], $this->inCalls))	unset($this->inCalls[$ed["cid"]]);
		if (array_key_exists($ed["cid"], $this->odCalls))	unset($this->odCalls[$ed["cid"]]);

	}
	
	///////////////////////////////////////////////////////// VIA OP 
	
	protected function dettThruPO($ed) {
		$callId = getVal($ed,"cli");
		if ($callId!="") {
			$cd = $this->getDetCallDetails($ed["uia"]);
			
			if ($cd!=[]) {
			
				echo "CHIAMATA VIA OP\n";
				// print_r($cd);
						
				$this->odCalls[$ed["uia"]] =  [
					"callId"		=>	$cd["callId"]
				,	"dettId"		=>	$cd["dettId"]
				,	"org"			=>	$cd["org"]
				,	"orgDescr"		=>	$cd["orgDescr"]
				,	"dstDescr"		=>	$cd["dstDescr"]
				,	"dst"			=>	$cd["dst"]
				,	"rate"			=>	$cd["rate"]
				,	"drpCharge"		=>	$cd["drpCharge"]
				,	"minCharge"		=>	$cd["minCharge"]
				,	"secsAvail"		=>	$cd["secsAvail"]
				,	"secsMax"		=>	$cd["secsMax"]
				,	"secsGrace"		=>	$cd["secsGrace"]
				,	"creditInit"	=>	$cd["creditInit"]
				,	"record"		=>	$cd["record"]
				,	"opExt"			=>	$ed["num"]
				];
				
				
				$this->sendMsg([
					"evtDescr"	=>	"Detenuto Chiamata via Op"
				,	"uid"		=>	$ed["uia"]
				,	"org"		=>	getVal($cd,"org")
				,	"orgDescr"	=>	getVal($cd,"orgDescr")
				,	"dst"		=>	getVal($cd,"dst")
				,	"dstDescr"	=>	getVal($cd,"dstDescr")
				,	"bgcol"		=>	CRED
				,	"blink"		=>	1
				,	"hangup"	=>	0
				,	"record"	=>	getVal($cd,"record",0)
				,	"secsGrace"	=>	getVal($cd,"secsGrace",0)
				,	"secsMax"	=>	getVal($cd,"secsMax",0)
				,	"opExt"		=>	$ed["num"]
				,	"uiOP"		=>	$ed["uid"]
				]);									
			}
		}
		
	}

	protected function dettThruPOAnswered($ed) {
		
		echo "Chiamata via op risposta\n";
		
		// print_r ($this->odCalls[$ed["uia"]]);
		$od = $this->odCalls[$ed["uia"]];
		
		$this->sendMsg([
			"evtDescr"	=>	"Detenuto Da PO risposta"
		,	"uid"		=>	$ed["uia"]
		,	"bgcol"		=>	CRED
		,	"blink"		=>	0
		,	"hangup"	=>	0
		]);			
		
	} 
	
	protected function dettThruPOOriginate($ed) {
		// evt:CHANNEL_ORIGINATE det: uid:CCCC_2968a80b-3cc5-4a35-871d-f6f1d22b14d5 uia: cid:DDDD_2968a80b-3cc5-4a35-871d-f6f1d22b14d5 oid:DDDD_2968a80b-3cc5-4a35-871d-f6f1d22b14d5 stt:CS_INIT ext: num:1000 int: opa: ofd:1 cli:
		$callId = substr($ed["uid"],5);
		$cd = $this->getDetCallDetails($callId);

		$this->sendMsg([
			"evtDescr"	=>	"Operatore chiama per Dett"
		,	"uid"		=>	$ed["cid"]
		,	"org"		=>	"Operatore " . $ed["opx"]
		,	"orgDescr"	=>	$cd["orgDescr"]
		,	"dst"		=>	$cd["dst"]
		,	"dstDescr"	=>	$cd["dstDescr"]
		,	"bgcol"		=>	CYELLOW
		,	"blink"		=>	1
		,	"hangup"	=>	0
		,	"record"	=>	$cd["record"]
		]);		
		
	}	

	protected function dettThruPOConnected($ed) {
		
		echo "Chiamata da OP per dett risposta da altra parte\n";
		
		// print_r ($this->odCalls[$ed["uia"]]);
		$uidOrig = substr($ed["uid"],5);
		$this->odCalls[$uidOrig]["opStart"] = time();
		
		$this->sendMsg([
			"evtDescr"	=>	"Detenuto Da PO per DeTT risposta"
		,	"uid"		=>	$ed["uid"]
		,	"bgcol"		=>	CORANGE
		,	"blink"		=>	0
		,	"hangup"	=>	0
		,	"ctStart"	=>	time() 
		]);			
		
	} 

	protected function dettThruTransfered($ed) {
		echo "PO trasferisce chiamata OA a DT\n";

		$this->odCalls[$ed["uid"]]["dtStart"] = time();

		$this->sendMsg([
			"evtDescr"	=>	"Detenuto Da PO per DeTT risposta"
		,	"uid"		=>	$ed["oid"]
		,	"bgcol"		=>	CGREEN
		,	"tmbeg"		=>	gmdate("H:i:s")
		,	"org"		=>	$this->odCalls[$ed["uid"]]["org"]
		,	"dstDescr"	=>	$this->odCalls[$ed["uid"]]["dstDescr"]
		,	"blink"		=>	0
		,	"hangup"	=>	0
		,	"ctStart"	=>	time() 
		]);			
	}
	
	protected function dettThruPOTerminate($ed) {

		echo "Fine chiamata Detenuto tramite PO\n";

// DebugBreak();		
		$uidOrig = substr($ed["uid"],5);
		$odCall = $this->odCalls[$uidOrig];
		echo "*************************** BILL CALL !\n";
	
/// TIME TO BILL INCLUDES op TIME
		$billSecs 	= time() - intval($odCall["opStart"]) - intval($odCall["secsGrace"]);
		$talkSecs 	= time() - intval($odCall["dtStart"]);
		
		$req = [
			"callId"	=> 	$odCall["callId"]
		,	"totSecs"	=>	time() - intval($odCall["opStart"]) - intval($odCall["secsGrace"])
		,	"talkSecs"	=>	time() - intval($odCall["opStart"]) - intval($odCall["secsGrace"])
		,	"realSecs"	=>	time() - intval($odCall["dtStart"])
		,	"dialDTTM"	=>	date("Y-m-d H:i:s", intval($odCall["opStart"]) )	
		,	"ansDTTM"	=>	date("Y-m-d H:i:s", intval($odCall["dtStart"]) )	
		,	"endDTTM"	=>	date("Y-m-d H:i:s", time())
        ,	"cause"		=>	"NORMAL_CLEARING"
		];
	
		$ret = $this->billCall($req);
		if($ret===0)
			echo "... Call billed sucessfully\n";
		else
			echo "****** BILLING ERROR: $ret **************\n";
		
		$this->sendMsg([
			"evtDescr"	=>	"Fine chiamata Detenuto tramite PO"
		,	"uid"		=>	$ed["uid"]
		,	"hangup"	=>	1
		]);			

		$this->sendMsg([
			"evtDescr"	=>	"Fine chiamata Detenuto tramite PO"
		,	"uid"		=>	$uidOrig
		,	"hangup"	=>	1
		]);			
		
	} 	
	///////////////////////////////////////////////////////// END EVENTS 


	protected function billCall($req) {
		$callId = getVal($req,"callId",0);
		$totSecs	= intval(getVal($req,"totSecs",0));
		$talkSecs	= intval(getVal($req,"talkSecs",0));
		$realSecs 	= intval(getVal($req,"realSecs",0));
		$dialDTTM	= getVal($req,"dialDTTM");
		$ansDTTM	= getVal($req,"ansDTTM");
		$endDTTM	= getVal($req,"endDTTM");
		$endDTTM	= getVal($req,"endDTTM");
        $cause		= getVal($req,"cause");
// DebugBreak("1@192.168.0.101");        
		$my = new mySqliDb(T3_SRV, T3_USR, T3_PWD, T3_DB);
		
		$rows = $my->getSQL("SELECT * FROM callrec WHERE callId=$callId");
		if ($rows===-1)	return($my->getLastErr());
		if ($rows===0)	return("call with callId $callId NOT found!");
		$call = $rows[0];

		$rate 		= floatVal($call["rate"]);
		$drpCharge	= floatVal($call["drpCharge"]);
		$minCharge 	= floatval($call["minCharge"]);
		
		if ($talkSecs <= 0) {
			$totCharge = 0;
			$status = ERR_NO_ANSWER;
		} else {			
			$totCharge = ($talkSecs * floatval($call["rate"]) / 60) + $drpCharge;
			if ($totCharge < $minCharge)	
				$totCharge = $minCharge;
			$totCharge = round($totCharge, 2);			
			$status = 0;
		}
		
		$sql = "UPDATE callrec
				SET totSecs=$totSecs
				,	talkSecs=$realSecs
				,	totCharge=$totCharge
		  		,	status=$status
		  		,	cause='$cause'
		  		,	dialDTTM='$dialDTTM'";
		
		if ($ansDTTM!="")	$sql .= "\n,	ansDTTM='$ansDTTM'";
		if ($endDTTM!="")	$sql .= "\n,	endDTTM='$endDTTM'";
		$sql .= "\nWHERE callId=$callId";
		echo "\n---------- SQL --------------\n$sql\n";

// DebugBreak("1@192.168.0.101");
		
		if(!$my->doSQL($sql)) 
			return("Updating call: " . $my->getLastErr());
		else
			return(0);
		
	}
	protected function getDetCallDetails($uid) {
		$sql = "SELECT 
					r.callId
				,	r.dettId
				,	r.secsAvail
				,	r.secsMax	
				,	concat('Int:' ,r.ext, ' Tessera:', c.serial) org
				,	CONCAT(d.lname, ' ', d.fname) orgDescr
				,	r.dialedNum dst
				,	CONCAT(CASE r.callTip
						WHEN 'N' THEN 'Ord'
						WHEN 'A'	THEN 'Avv'
						WHEN 'S'	THEN 'Sup'
						WHEN 'X' THEN 'Str'
					END, ' - ' , w.descr) dstDescr
				,	r.rate
				,	r.drpCharge
				,	r.minCharge
				,	r.creditInit
				,	r.secsGrace
				,	r.secsMax
				,	r.record
				FROM callrec r
				JOIN card c ON c.cardId = r.cardId
				JOIN dett d ON d.dettId = r.dettId
				JOIN wl 	w ON w.wlId = r.wlId
				WHERE r.uuid = '$uid'";

		$my = new mySqliDb(T3_SRV, T3_USR, T3_PWD, T3_DB);
		$rows = $my->myGetRows($sql);
		if ($rows===-1)	{
			dbg(9,"**Errore SQL" . $my->getLastErr());
			return([]);
		}
		if ($rows===0) {
			dbg(9,"**Chiamata NON trovata $uid");
			return([]);
		}
		
		return($rows[0]);
	}

	protected function doApi($cmd,$pars="") {
		$e = $this->eslOut->api($cmd, $pars);
		$d = json_decode($e->serialize("json"),true);
		echo "API Command Executed $cmd $pars\n";
		print_r($d);
		return($d);
	}
	
	protected function sendMsg($msg) {
		$msg["type"] = "calldata";
		$this->broadcast($msg);
	}
	
	protected function evtDettDump($ed) {
		dbg(1, "evt:" . $ed["evt"]
		. " det:" . $ed["det"]
		. " uid:" . $ed["uid"]
		. " uia:" . $ed["uia"]
		. " cid:" . $ed["cid"]
		. " oid:" . $ed["oid"]
		. " stt:" . $ed["stt"]
		. " ext:" . $ed["ext"]
		. " num:" . $ed["num"]
		. " int:" .	$ed["int"]
		. " opa:" .	$ed["opa"]
		. " ofd:" .	$ed["ofd"]
		. " cli:" .	$ed["cli"]
		, " rid:" . $ed["rid"]
		, " opx:" . $ed["opx"]
		);
	}	

	protected function opExtSet() {
		$this->opExt = explode(",", OP_EXT);
		if (sizeof($this->opExt)==0)	{
			dbg(9,"No operator extentions defined in constant OP_EXT");
			return(false);
		}
		dbg(1,"Operator Extensions");
		dbg(1,$this->opExt);
		return(true);
		
	}
	
	protected function isDetExt($ext) {
		
		$my = new mySqliDb(T3_SRV, T3_USR, T3_PWD, T3_DB);
		$rows = $my->myGetRows("SELECT extNum FROM ext WHERE extNum='$ext'");
		if ($rows===-1)	{
			dbg(9,"Error getting det ext:" . $my->getLastErr());
			return(true);
		}
		if ($rows==0)	return(false);
		return(true);
		
	}
	
	
	protected function constLoad() {
    	dbg(1,"Getting constants");
    	$sql = "SELECT constName, constVal FROM const";
    	$my = new mySqliDb(T3_SRV, T3_USR, T3_PWD, T3_DB);
    	$rows = $my->myGetRows($sql);
    	
    	if ($rows===-1) {
			dbg(9,"**Error getting constants:");
			dbg(9, $my->getLastErr());
			return(false);
    	}

    	if ($rows===0) {
			dbg(9,"**No Constants Found!");
			return(false);
    	}

    	if ($rows!==0 && $rows!==-1) {
			foreach ($rows as $row) {
				define($row["constName"], $row["constVal"]);
				dbg(1,"\t" . $row["constName"] . " = " . $row["constVal"]);
			}
    	}
    	return($row["constVal"]);
	}

	protected function getSpyExt() {
		
		
		$tmpArr = explode(",", SPY_EXT);
		if (sizeof($tmpArr)==0)	{
			dbg(9, "NO spy extensions configured");
			return(false);
		}
		
		$spyExt = [];
		foreach($tmpArr as $ext) 
			$spyExt[$ext] = [
				"used"	=>	0
			];
		

		$d = $this->doApi("show registrations");
		$ret = $d["_body"];
		if (substr(trim($ret),0,8) != "reg_user")	{
			return(false);
		}

		$lines = explode("\n", $ret);
		for ($i=2; $i<sizeof($lines)-3; $i++) {
			$extRegged = trim(explode(",", $lines[$i])[0]);
			if (array_key_exists($extRegged, $spyExt))
				$spyExt[$extRegged]["used"] = 1;
		}

		$useExt = "";			
		foreach($spyExt as $k => $v) {
			if ($spyExt[$k]["used"]== "0")
				$useExt = $k;
		}
		if ($useExt == "")
			return(false);
		
		return($useExt);
		
		
	}
	
	protected function keyPressed($k) {
		if ($k == "\n") return;
		$k = strtoupper($k);

		echo "\n===============================================================================\n";
		

		switch($k) {
			case "P" : 
				echo "Prisoner Calls:\n";
				print_r($this->dtCalls);
				break;
		
			case "O" : 
				echo "Operator Calls:\n";
				print_r($this->opCalls);
				break;
				
			case "I" :
				echo "Incoming Calls:\n";
				print_r($this->inCalls);
				break;
				
			case "D" :
				echo "Via Op Calls:\n";
				print_r($this->odCalls);
				break;
			
			default :
				echo "Possible commands:\n";
				echo "P : Prisoner Calls\n";
				echo "O : Operator Calls\n";
				echo "I : Incoming Calls\n";
				echo "D : Via Op Calls\n";
			
		}
		
		echo "\n===============================================================================\n";
		
	}
    
    protected function non_block_read($fd, &$data) {
	    $read = array($fd);
	    $write = array();
	    $except = array();
	    $result = stream_select($read, $write, $except, 0);
	    if($result === false) throw new Exception('stream_select failed');
	    if($result === 0) return false;
	    $data = stream_get_line($fd, 1);
	    return true;
	}
    

	////////////////////////////// CALL MON SOCKETS

	protected function broadcast($msg) {
		if ($msg["type"]!="heartbeat")	dbg(1,$msg);
			
		$msgMasked = $this->mask(json_encode($msg));
		foreach($this->clients as $changed_socket) {
			@socket_write($changed_socket,$msgMasked,strlen($msgMasked));
		}
		return true;
	}

	protected function unmask($text) {
		$length = ord($text[1]) & 127;
		if($length == 126) {
			$masks = substr($text, 4, 4);
			$data = substr($text, 8);
		}
		elseif($length == 127) {
			$masks = substr($text, 10, 4);
			$data = substr($text, 14);
		}
		else {
			$masks = substr($text, 2, 4);
			$data = substr($text, 6);
		}
		$text = "";
		for ($i = 0; $i < strlen($data); ++$i) {
			$text .= $data[$i] ^ $masks[$i%4];
		}
		return $text;
	}

	protected function mask($text) {
		$b1 = 0x80 | (0x1 & 0x0f);
		$length = strlen($text);
		
		if($length <= 125)
			$header = pack('CC', $b1, $length);
		elseif($length > 125 && $length < 65536)
			$header = pack('CCn', $b1, 126, $length);
		elseif($length >= 65536)
			$header = pack('CCNN', $b1, 127, $length);
		return $header.$text;
	}
/***
	protected function perform_handshaking($receved_header,$client_conn, $host, $port) {
		$headers = array();
		$lines = preg_split("/\r\n/", $receved_header);
		print_r($lines);

		foreach($lines as $line) 		{
			$line = chop($line);
			if(preg_match('/\A(\S+): (.*)\z/', $line, $matches)) 			{
				$headers[$matches[1]] = $matches[2];
			}
		}
		$secKey = $headers['Sec-WebSocket-Key'];
		// $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)));
		$secAccept = base64_encode(SHA1($secKey."258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
		//hand shaking header
		$upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
		"Upgrade: websocket\r\n" .
		"Connection: Upgrade\r\n" .
		"WebSocket-Origin: $host\r\n" .
		"WebSocket-Location: ws://$host:$port/demo/shout.php\r\n".
		"Sec-WebSocket-Accept:$secAccept\r\n\r\n";
		socket_write($client_conn,$upgrade,strlen($upgrade));
	}	
***/

	protected function perform_handshaking($receved_header,$client_conn, $host, $port) {

		$request = $receved_header;
		print_r($request);
		preg_match('#Sec-WebSocket-Key: (.*)\r\n#', $request, $matches);
		echo "\nMATCHES[1] = " . $matches[1] . "\n";
		$key = base64_encode(pack(
		    'H*',
		    sha1($matches[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')
		));
		$headers = "HTTP/1.1 101 Switching Protocols\r\n";
		$headers .= "Upgrade: websocket\r\n";
		$headers .= "Connection: Upgrade\r\n";
		$headers .= "Sec-WebSocket-Version: 13\r\n";
		$headers .= "Sec-WebSocket-Accept: $key\r\n\r\n";
		socket_write($client_conn, $headers, strlen($headers));	
	}	
	///////////////////////////////////////////////////
	
	function __construct() {
    	dbg(3,"Starting Up\n\n");
    	// $this->my = new mySqliDb(T3_SRV, T3_USR, T3_PWD, T3_DB);
    }
    
    function __destruct() {
    	// unset($this->my);
    }    			
	
	
}

system('clear');
echo "Version " . VER . "\n";
$cm = new CallMonSrv();
if(!$cm->mainLoop())	exit;


