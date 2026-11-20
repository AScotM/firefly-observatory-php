<?php
declare(strict_types=1);

const VERSION = '1.0.0';
const TAU = M_PI * 2.0;
const GLYPHS = ' .,:;irsXA253hMHGS#9B&@';
const PALETTES = [
    'aurora' => [[40,190,190],[70,255,160],[80,125,255],[255,195,110]],
    'ember' => [[255,110,45],[255,195,60],[255,80,100],[255,235,160]],
    'moon' => [[80,140,225],[150,195,255],[190,165,255],[215,235,255]],
    'forest' => [[40,145,80],[100,220,100],[180,245,100],[255,215,100]],
    'ocean' => [[25,120,195],[45,220,230],[120,175,255],[170,250,245]],
    'violet' => [[130,65,225],[205,105,245],[255,145,220],[115,175,255]],
    'mono' => [[120,120,120],[170,170,170],[215,215,215],[255,255,255]],
];
const MODES = ['flock','drift','orbit','breathe','swarm','rain'];

function clamp(float $v, float $lo, float $hi): float { return max($lo, min($hi, $v)); }
function wrap(float $v, float $max): float { $v = fmod($v, $max); return $v < 0 ? $v + $max : $v; }
function delta(float $a, float $b, float $size): float { $d = $b - $a; if ($d > $size / 2) $d -= $size; if ($d < -$size / 2) $d += $size; return $d; }
function randf(): float { return mt_rand() / mt_getrandmax(); }
function normal(): float { return sqrt(-2 * log(max(1.0e-12, randf()))) * cos(TAU * randf()); }
function dimensions(): array {
    $s = trim((string) @shell_exec('stty size 2>/dev/null'));
    if (preg_match('/^(\d+)\s+(\d+)$/', $s, $m)) return [(int)$m[2], (int)$m[1]];
    return [100, 32];
}
function options(array $argv): array {
    $defaults = ['agents'=>420,'fps'=>24,'width'=>0,'height'=>0,'seed'=>0,'palette'=>'aurora','mode'=>'flock','coupling'=>1.4,'radius'=>6.5,'speed'=>1.0,'noise'=>0.22,'trails'=>0.83,'duration'=>0.0,'density'=>1.0,'wind'=>0.08,'gravity'=>0.04,'bloom'=>0.85,'frames'=>0,'output'=>'','snapshot'=>'','restore'=>'','record'=>'','text'=>'','telemetry'=>false,'headless'=>false,'ascii'=>false,'eco'=>false,'stats'=>true,'border'=>false];
    $command = 'run'; $args = array_slice($argv, 1);
    if ($args && !str_starts_with($args[0], '-')) $command = array_shift($args);
    $flags = ['telemetry','headless','ascii','eco','stats','border'];
    for ($i=0; $i<count($args); $i++) {
        $arg=$args[$i];
        if ($arg === '--help' || $arg === '-h') return ['help',$defaults];
        if (!str_starts_with($arg, '--')) throw new InvalidArgumentException("Unexpected argument: $arg");
        $part=explode('=', substr($arg,2),2); $key=str_replace('-', '_', $part[0]);
        if (!array_key_exists($key,$defaults)) throw new InvalidArgumentException("Unknown option: --$key");
        $value=$part[1] ?? null;
        if (in_array($key,$flags,true)) {
            if ($value === null && isset($args[$i+1]) && in_array(strtolower($args[$i+1]),['true','false','0','1'],true)) $value=$args[++$i];
            $defaults[$key] = $value === null ? true : filter_var($value,FILTER_VALIDATE_BOOLEAN);
        } else {
            $value ??= $args[++$i] ?? throw new InvalidArgumentException("Missing value for --$key");
            $defaults[$key] = is_int($defaults[$key]) ? (int)$value : (is_float($defaults[$key]) ? (float)$value : $value);
        }
    }
    return [$command,$defaults];
}
final class World {
    public array $agents=[];
    public array $grid=[];
    public int $cols=1,$rows=1,$frame=0;
    public float $cell=1.0,$time=0.0,$order=0.0,$energy=0.0;
    public array $telemetry=['cpu'=>0.0,'memory'=>0.0,'io'=>0.0];
    private array $cpuPrev=[];
    private int $ioPrev=0;
    public function __construct(public array $c, ?array $saved=null) {
        mt_srand((int)$c['seed']);
        if ($saved !== null) {
            $this->agents=$saved['agents']; $this->frame=(int)($saved['frame']??0); $this->time=(float)($saved['time']??0);
            return;
        }
        for ($i=0;$i<$c['agents'];$i++) $this->agents[]=[
            'x'=>randf()*$c['width'],'y'=>randf()*$c['height'],'vx'=>(randf()-.5)*2,'vy'=>(randf()-.5)*2,
            'phase'=>randf()*TAU,'frequency'=>.65+randf()*1.3,'energy'=>.5+randf()*.5,
            'size'=>.6+randf()*.8,'family'=>mt_rand(0,3),'age'=>0.0
        ];
    }
    private function grid(): void {
        $c=$this->c; $this->cell=max(1.0,$c['radius']);
        $this->cols=max(1,(int)ceil($c['width']/$this->cell)); $this->rows=max(1,(int)ceil($c['height']/$this->cell));
        $this->grid=[];
        foreach ($this->agents as $i=>$a) {
            $x=min($this->cols-1,(int)($a['x']/$this->cell)); $y=min($this->rows-1,(int)($a['y']/$this->cell));
            $this->grid[$y*$this->cols+$x][]=$i;
        }
    }
    public function step(float $dt): void {
        $this->grid(); $c=$this->c; $next=[]; $sx=0.0;$sy=0.0;$energy=0.0;
        $centerX=$c['width']/2; $centerY=$c['height']/2; $radius2=$c['radius']**2;
        foreach ($this->agents as $i=>$a) {
            $count=0; $sinSum=0.0;$alignX=0.0;$alignY=0.0;$repelX=0.0;$repelY=0.0;
            $cx=(int)($a['x']/$this->cell); $cy=(int)($a['y']/$this->cell); $seen=[];
            for ($dy=-1;$dy<=1;$dy++) for ($dx=-1;$dx<=1;$dx++) {
                $x=($cx+$dx+$this->cols)%$this->cols; $y=($cy+$dy+$this->rows)%$this->rows;
                $key=$y*$this->cols+$x; if (isset($seen[$key])) continue; $seen[$key]=true;
                foreach ($this->grid[$key]??[] as $j) {
                    if ($i===$j) continue; $b=$this->agents[$j];
                    $xx=delta($a['x'],$b['x'],$c['width']); $yy=delta($a['y'],$b['y'],$c['height']);
                    $d2=$xx*$xx+$yy*$yy; if ($d2 >= $radius2) continue;
                    $sinSum+=sin($b['phase']-$a['phase']);$alignX+=$b['vx'];$alignY+=$b['vy'];
                    if ($d2>1.0e-6) { $f=1/($d2+.2);$repelX-=$xx*$f;$repelY-=$yy*$f; }
                    $count++;
                }
            }
            $coupling=$c['coupling']*($c['telemetry'] ? 1+.35*$this->telemetry['cpu'] : 1);
            $a['phase']=wrap($a['phase']+$dt*($a['frequency']+$coupling*$sinSum/max(1,$count)),TAU);
            $nx=normal()*$c['noise'];$ny=normal()*$c['noise'];
            switch ($c['mode']) {
                case 'flock': if ($count>0) { $nx+=.3*($alignX/$count-$a['vx'])+.12*$repelX;$ny+=.3*($alignY/$count-$a['vy'])+.12*$repelY; } break;
                case 'orbit': $dx=delta($a['x'],$centerX,$c['width']);$dy=delta($a['y'],$centerY,$c['height']);$r=max(1,hypot($dx,$dy));$nx+=-$dy/$r*.7+$dx/$r*.08;$ny+=$dx/$r*.7+$dy/$r*.08;break;
                case 'breathe': $nx+=sin($this->time*.7+$a['phase'])*.3;$ny+=cos($this->time*.7+$a['phase'])*.3;break;
                case 'swarm': $nx+=$repelX*.35+sin($this->time*.17+$a['family'])*.3;$ny+=$repelY*.35+cos($this->time*.13+$a['family'])*.3;break;
                case 'rain': $ny+=.6;$nx+=sin($this->time+$a['y']*.05)*.15;break;
                case 'drift': $nx+=sin($this->time*.2+$a['y']*.03)*.08;$ny+=cos($this->time*.2+$a['x']*.03)*.08;break;
            }
            if ($c['mode']!=='rain') { $nx+=delta($a['x'],$centerX,$c['width'])/max(1,$c['width'])*$c['gravity'];$ny+=delta($a['y'],$centerY,$c['height'])/max(1,$c['height'])*$c['gravity']; }
            $nx+=$c['wind']*.3;
            $a['vx']=clamp($a['vx']*.985+$nx*$dt,-2.8,2.8);$a['vy']=clamp($a['vy']*.985+$ny*$dt,-2.8,2.8);
            $a['x']=wrap($a['x']+$a['vx']*$dt*2.2,$c['width']);$a['y']=wrap($a['y']+$a['vy']*$dt*1.1,$c['height']);
            $a['age']+=$dt;$a['energy']=clamp(.55+.45*sin($a['phase']),0,1);
            $next[]=$a;$sx+=cos($a['phase']);$sy+=sin($a['phase']);$energy+=$a['energy'];
        }
        $this->agents=$next;$this->time+=$dt;$this->frame++;
        $n=max(1,count($next));$this->order=hypot($sx,$sy)/$n;$this->energy=$energy/$n;
    }
    public function poll(): void {
        $stat=@file('/proc/stat');
        if ($stat && preg_match('/^cpu\s+(.+)$/',$stat[0],$m)) {
            $parts=array_map('intval',preg_split('/\s+/',trim($m[1])));$total=array_sum($parts);$idle=($parts[3]??0)+($parts[4]??0);
            if ($this->cpuPrev) { $d=max(1,$total-$this->cpuPrev[0]);$this->telemetry['cpu']=clamp(1-($idle-$this->cpuPrev[1])/$d,0,1); }
            $this->cpuPrev=[$total,$idle];
        }
        $mem=@file_get_contents('/proc/meminfo');
        if ($mem && preg_match('/MemTotal:\s+(\d+)/',$mem,$a) && preg_match('/MemAvailable:\s+(\d+)/',$mem,$b)) $this->telemetry['memory']=clamp(1-(int)$b[1]/max(1,(int)$a[1]),0,1);
        $disk=@file('/proc/diskstats');$sum=0;
        if ($disk) foreach ($disk as $line) { $p=preg_split('/\s+/',trim($line));if (count($p)>=14 && !preg_match('/^(loop|ram|fd)/',$p[2])) $sum+=(int)$p[5]+(int)$p[9]; }
        $this->telemetry['io']=$this->ioPrev>0 ? max(0,$sum-$this->ioPrev)*512/1048576 : 0;$this->ioPrev=$sum;
    }
    public function snapshot(): array { return ['version'=>VERSION,'config'=>$this->c,'frame'=>$this->frame,'time'=>$this->time,'order'=>$this->order,'energy'=>$this->energy,'agents'=>$this->agents]; }
}
final class Field {
    public array $r=[],$g=[],$b=[];
    public function __construct(public int $w,public int $h) { $n=$w*$h;$this->r=array_fill(0,$n,0.0);$this->g=array_fill(0,$n,0.0);$this->b=array_fill(0,$n,0.0); }
    public function fade(float $k): void { $n=$this->w*$this->h;for ($i=0;$i<$n;$i++) { $this->r[$i]*=$k;$this->g[$i]*=$k;$this->b[$i]*=$k; } }
    public function glow(float $x,float $y,float $r,array $color,float $strength): void {
        $r=max(.3,$r);$span=(int)ceil($r*2.7);$ix=(int)$x;$iy=(int)$y;$den=2*$r*$r;
        for ($dy=-$span;$dy<=$span;$dy++) { $py=$iy+$dy;if ($py<0||$py>=$this->h) continue;
            for ($dx=-$span;$dx<=$span;$dx++) { $px=$ix+$dx;if ($px<0||$px>=$this->w) continue;
                $xx=$px-$x;$yy=($py-$y)*1.7;$v=$strength*exp(-($xx*$xx+$yy*$yy)/$den);$i=$py*$this->w+$px;
                $this->r[$i]=min(255,$this->r[$i]+$color[0]*$v);$this->g[$i]=min(255,$this->g[$i]+$color[1]*$v);$this->b[$i]=min(255,$this->b[$i]+$color[2]*$v);
            }
        }
    }
    public function paint(World $world): void {
        $c=$world->c;$this->fade($c['trails']);$colors=PALETTES[$c['palette']];
        foreach ($world->agents as $a) {
            $pulse=$a['energy']**3;$v=(.06+$pulse*.8)*$c['density'];
            $this->glow($a['x'],$a['y'],(.65+$a['size']*.4)*$c['bloom'],$colors[$a['family']%4],$v);
            if ($pulse>.72) $this->glow($a['x'],$a['y'],.45,[255,255,235],($pulse-.7)*1.5);
        }
    }
    public function ansi(World $world): string {
        $c=$world->c;$out="\033[H";$last='';$chars=GLYPHS;
        for ($y=0;$y<$this->h;$y++) {
            for ($x=0;$x<$this->w;$x++) {
                $i=$y*$this->w+$x;$r=(int)$this->r[$i];$g=(int)$this->g[$i];$b=(int)$this->b[$i];
                $lum=clamp(($r*.2126+$g*.7152+$b*.0722)/255,0,1);
                if ($c['ascii']) { $out.=$chars[(int)($lum*(strlen($chars)-1))];continue; }
                $glyph=$lum>.76?'✶':($lum>.43?'✦':($lum>.15?'•':($lum>.045?'·':' ')));
                $code="$r;$g;$b";if ($code!==$last) { $out.="\033[38;2;{$code}m";$last=$code; }$out.=$glyph;
            }
            $out.="\033[0m\033[K";$last='';if ($y<$this->h-1) $out.="\n";
        }
        if ($c['stats']) { $out.=sprintf("\n\033[2m firefly observatory | %s | %d agents | t %.1fs | coherence %.3f | glow %.3f | seed %d\033[0m\033[K",$c['mode'],count($world->agents),$world->time,$world->order,$world->energy,$c['seed']);
            if ($c['telemetry']) $out.=sprintf("\n\033[2m CPU %.1f%% | memory %.1f%% | disk %.1f MB/tick\033[0m\033[K",$world->telemetry['cpu']*100,$world->telemetry['memory']*100,$world->telemetry['io']);
        }
        return $out;
    }
    public function ppm(string $path): void {
        $f=fopen($path,'wb');if (!$f) throw new RuntimeException("Cannot write $path");
        fwrite($f,"P6\n{$this->w} {$this->h}\n255\n");$buffer='';$n=$this->w*$this->h;
        for ($i=0;$i<$n;$i++) { $buffer.=chr((int)$this->r[$i]).chr((int)$this->g[$i]).chr((int)$this->b[$i]);if (strlen($buffer)>=65536) { fwrite($f,$buffer);$buffer=''; } }
        if ($buffer!=='') fwrite($f,$buffer);fclose($f);
    }
    public function text(string $path): void {
        $s='';$chars=GLYPHS;$n=strlen($chars)-1;
        for ($y=0;$y<$this->h;$y++) { for ($x=0;$x<$this->w;$x++) { $i=$y*$this->w+$x;$lum=clamp(($this->r[$i]*.2126+$this->g[$i]*.7152+$this->b[$i]*.0722)/255,0,1);$s.=$chars[(int)($lum*$n)]; }$s.="\n"; }
        if ($path==='-') echo $s;else file_put_contents($path,$s);
    }
}
function help(): void {
    echo <<<'TXT'
Firefly Observatory PHP — native terminal bioluminescence

Usage: php main.php [run|render|inspect|modes|palettes|version] [options]

--mode NAME       flock, drift, orbit, breathe, swarm, rain
--palette NAME    aurora, ember, moon, forest, ocean, violet, mono
--agents N        number of fireflies (default 420)
--fps N           target frames per second (default 24)
--width N         canvas width (0 = terminal)
--height N        canvas height (0 = terminal)
--frames N        number of frames (render defaults to 240)
--seed N          deterministic seed (0 = random)
--coupling X      phase synchronization strength
--radius X        neighborhood radius
--speed X         simulation speed
--noise X         movement noise
--trails X        persistence 0..0.99
--bloom X         glow radius multiplier
--density X       glow brightness multiplier
--wind X          horizontal drift
--gravity X       central attraction
--duration X      stop after X seconds
--output PATH     PPM export
--text PATH       ASCII export, use - for stdout
--snapshot PATH   JSON world snapshot
--restore PATH    restore a JSON snapshot
--record PATH     write per-frame JSONL telemetry
--telemetry       read Linux /proc activity
--ascii           use ASCII glyphs
--headless        suppress animation
--eco             cap FPS at 12 and agents at 240
--stats           show stats (use --stats=false to hide)
--help            show this help

Examples:
  php main.php --mode flock --palette aurora
  php main.php render --frames 100 --output scene.ppm --snapshot scene.json
  php main.php --mode breathe --palette moon --agents 120 --fps 12 --eco

TXT;
}
function main(array $argv): int {
    [$command,$c]=options($argv);
    if ($command==='help') {help();return 0;}
    if ($command==='version') {echo 'Firefly Observatory PHP '.VERSION.' | PHP '.PHP_VERSION." | GPL-3.0-only\n";return 0;}
    if ($command==='modes') {echo implode("\n",MODES)."\n";return 0;}
    if ($command==='palettes') {echo implode("\n",array_keys(PALETTES))."\n";return 0;}
    if (!in_array($command,['run','render','inspect'],true)) throw new InvalidArgumentException("Unknown command: $command");
    if (!in_array($c['mode'],MODES,true)||!isset(PALETTES[$c['palette']])) throw new InvalidArgumentException('Invalid mode or palette');
    if ($c['agents']<1||$c['agents']>10000||$c['fps']<1||$c['fps']>240||$c['radius']<=0||$c['speed']<=0||$c['trails']<0||$c['trails']>.99) throw new InvalidArgumentException('Invalid numeric option');
    if ($c['eco']) { $c['fps']=min(12,$c['fps']);if (!$c['restore']) $c['agents']=min(240,$c['agents']); }
    [$tw,$th]=dimensions();$c['width']=$c['width']?:$tw;$c['height']=$c['height']?:max(6,$th-3);
    $c['width']=(int)clamp($c['width'],12,500);$c['height']=(int)clamp($c['height'],6,200);
    if (!$c['seed']) $c['seed']=random_int(1,2147483647);
    if ($command==='render') { $c['headless']=true;if (!$c['frames']) $c['frames']=240; }
    if ($command==='inspect') { $c['headless']=true;if (!$c['frames']) $c['frames']=120; }
    if ($c['headless'] && $c['frames']===0 && $c['duration']<=0) throw new InvalidArgumentException('Headless execution requires --frames or --duration');
    $saved=null;
    if ($c['restore']) { $saved=json_decode((string)file_get_contents($c['restore']),true,512,JSON_THROW_ON_ERROR);if (!isset($saved['agents'])||!is_array($saved['agents'])) throw new RuntimeException('Invalid snapshot');$c['agents']=count($saved['agents']); }
    $world=new World($c,$saved);$field=new Field($c['width'],$c['height']);
    $record=$c['record'] ? fopen($c['record'],'wb') : false;
    if ($c['record'] && !$record) throw new RuntimeException('Cannot open recording');
    $start=microtime(true);$next=$start;$interval=1/$c['fps'];$dt=$c['speed']/$c['fps'];
    if (!$c['headless']) { echo "\033[?25l\033[2J\033[H";register_shutdown_function(static function():void {echo "\033[0m\033[?25h\n";}); }
    try {
        while (true) {
            $now=microtime(true);
            if ($c['duration']>0 && $now-$start >= $c['duration']) break;
            if ($c['frames']>0 && $world->frame >= $c['frames']) break;
            if ($c['telemetry'] && $world->frame%max(1,$c['fps'])===0) $world->poll();
            $world->step($dt);$field->paint($world);
            if ($record) fwrite($record,json_encode(['type'=>'frame','frame'=>$world->frame,'time'=>$world->time,'order'=>$world->order,'energy'=>$world->energy,'agents'=>count($world->agents),'telemetry'=>$world->telemetry],JSON_THROW_ON_ERROR)."\n");
            if (!$c['headless']) {echo $field->ansi($world);flush();$next+=$interval;$sleep=$next-microtime(true);if ($sleep>0) usleep((int)($sleep*1e6));else $next=microtime(true);}
        }
    } finally { if ($record) fclose($record); }
    if ($c['output']) $field->ppm($c['output']);
    if ($c['text']) $field->text($c['text']);
    if ($c['snapshot']) file_put_contents($c['snapshot'],json_encode($world->snapshot(),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    if ($c['headless']) printf("Firefly PHP | frames %d | agents %d | coherence %.4f | energy %.4f | elapsed %.2fs\n",$world->frame,count($world->agents),$world->order,$world->energy,microtime(true)-$start);
    return 0;
}
try {exit(main($argv));}catch(Throwable $e) {fwrite(STDERR,'firefly: '.$e->getMessage()."\n");exit(1);}
