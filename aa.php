<?php
//比如说：某个查询数据库的接口，因为调用量比较大，所以加了缓存，并设定缓存过期后刷新，问题是当并发量比较大的时候，如果没有锁机制，那么缓存过期的瞬间，大量并发请求会穿透缓存直接查询数据库，造成雪崩效应，如果有锁机制，那么就可以控制只有一个请求去更新缓存，其它的请求视情况要么等待，要么使用过期的缓存。
//下面以目前PHPRedis扩展为例，实现一段演示代码：

$ok = $redis->setNX($key, $value);
if ($ok) {
    $cache->update();
    $redis->del($key);
}
?>
<!--缓存过期时，通过SetNX获取锁，如果成功了，那么更新缓存，然后删除锁。看上去逻辑非常简单，可惜有问题：如果请求执行因为某些原因意外退出了，导致创建了锁但是没有删除锁，那么这个锁将一直存在，以至于以后缓存再也得不到更新。于是乎我们需要给锁加一个过期时间以防不测：-->
<?php
$redis->multi();
$redis->setNX($key, $value);
$redis->expire($key, $ttl);
$redis->exec();
?>
<!--因为SetNX不具备设置过期时间的功能，所以我们需要借助Expire来设置，同时我们需要把两者用Multi/Exec包裹起来以确保请求的原子性，以免SetNX成功了Expire却失败了。 可惜还有问题：当多个请求到达时，虽然只有一个请求的SetNX可以成功，但是任何一个请求的Expire却都可以成功，如此就意味着即便获取不到锁，也可以刷新过期时间，如果请求比较密集的话，那么过期时间会一直被刷新，导致锁一直有效。于是乎我们需要在保证原子性的同时，有条件的执行Expire，接着便有了如下Lua代码：-->
<!--local key   = KEYS[1]-->
<!--local value = KEYS[2]-->
<!--local ttl   = KEYS[3]-->
<!--local ok = redis.call('setnx', key, value)-->
<!--if ok == 1 then-->
<!--redis.call('expire', key, ttl)-->
<!--end-->
<!--return ok-->
<!--没想到实现一个看起来很简单的功能还要用到Lua脚本，着实有些麻烦。其实Redis已经考虑到了大家的疾苦，从 2.6.12 起，SET涵盖了SETEX的功能，并且SET本身已经包含了设置过期时间的功能，也就是说，我们前面需要的功能只用SET就可以实现。-->
<?php
$ok = $redis->set($key, $value, array('nx', 'ex' => $ttl));
if ($ok) {
    $cache->update();
    $redis->del($key);
}
?>
<!--如上代码是完美的吗？答案是还差一点！设想一下，如果一个请求更新缓存的时间比较长，甚至比锁的有效期还要长，导致在缓存更新过程中，锁就失效了，此时另一个请求会获取锁，但前一个请求在缓存更新完毕的时候，如果不加以判断直接删除锁，就会出现误删除其它请求创建的锁的情况，所以我们在创建锁的时候需要引入一个随机值：-->
<?php
$ok = $redis->set($key, $random, array('nx', 'ex' => $ttl));
if ($ok) {
    $cache->update();
    if ($redis->get($key) == $random) {
        $redis->del($key);
    }
}
?>
<!--补充：本文在删除锁的时候，实际上是有问题的，没有考虑到 GC pause 之类的问题造成的影响，比如A请求在DEL之前卡住了，然后锁过期了，这时候B请求又成功获取到了锁，此时A请求缓过来了，就会DEL掉B请求创建的锁，此问题远比想象的要复杂。-->


<!--下面程序相对来说比较完善，也只是目前能想到最好的办法，但是不排除一定不存在其他问题：-->
<?php
$redis = new Redis();
$redis->connect('127.0.0.1',6379);
$redis_key = 'xxx';
if($redis->exists($redis_key)){
    $data_info = json_decode($redis->get($redis_key),true);
    if(time() < $data_info['update_time']){
        $data = $data_info['data'];
    }else{
        $redis_lock_key = $redis_key . '_mutex';
        $random_val = rand(1000, 9999);
        $ret = $redis->set($redis_lock_key,$random_val,'EX',120,'NX');
        if(strtolower($ret) == 'ok'){
            $data = '读数据库';
            $redis->set($redis_key,json_encode(array('data'=>$data,'update_time'=>time()+'缓存更新时间')));

            //删除锁(lua脚本,原子操作)
            $lua_script = '
                        if redis.call("get", KEYS[1]) == ARGV[1] then
                            return redis.call("del", KEYS[1])
                        else
                              return 0
                        end';
            $ret2 = $redis->eval($lua_script, 1, $redis_lock_key, $random_val);
            $redis->delete($redis_lock_key);
        }else{
            $data = $data_info['data'];
        }
    }
}else{
    $data = '读数据库';
    $redis->set($redis_key,json_encode(array('data'=>$data,'update_time'=>time()+'缓存更新时间')));
}

//上面程序的优化：
//1，不要设置固定的字符串，而是设置为随机的大字符串，可以称为token。
//2，通过脚步删除指定锁的key，而不是DEL命令。
//上述优化方法会避免下述场景：a客户端获得的锁（键$redis_lock_key ）已经由于过期时间到了被redis服务器删除，但是这个时候a客户端还去执行DEL命令。而b客户端已经在a设置的过期时间之后重新获取了这个同样key的锁，那么a执行DEL就会释放了b客户端加好的锁。
//
//
//
//if (存在redis的key) {
//$redis_info = ********；//读取key所对应的值$redis_info
//if (time() < $redis_info['update_time']) {
////缓存数据有效
//$data = $redis_info['data'];
//} else {
////缓存需要更新
//$redis_lock_key = $redis_key . '_mutex';
//$random_val = rand(1000, 9999);
//$ret = app('redis')->connection()->set($redis_lock_key, $random_val, 'EX', 120, 'NX');
//if (strtolower($ret) == 'ok') {
//$data = 从数据库中读取
//if (!empty($data)) {
//$redis_info = array('data' => $data , 'update_time' => time() + $cache_seconds);
//将$redis_info写入到redis
//}
//
////删除锁(lua脚本,原子操作)
//$lua_script = '
//if redis.call("get", KEYS[1]) == ARGV[1] then
//return redis.call("del", KEYS[1])
//else
//return 0
//end';
//$ret2 = app('redis')->connection()->eval($lua_script, 1, $redis_lock_key, $random_val);
//} else {
////没有获取到锁，返回旧数据
//$data = $redis_info['data'];
//}
//}
//} else {
//$data = 从数据库中读取/空值
//if (!empty($data)) {
//$redis_info = array('data' => $data, 'update_time' => time() + $cache_seconds);
//将$redis_info写入到redis
//}
//}
