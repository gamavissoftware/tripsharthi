import { useEffect, useState } from 'react'
import { View, TouchableOpacity } from 'react-native'
import { Text } from './T'
import { useAudioPlayer, useAudioPlayerStatus, setAudioModeAsync } from 'expo-audio'
import { Icon } from './ui'
import { c } from './theme'
import { mmss } from './VoiceBar'

/** A voice note that plays inside the chat bubble: play / pause, a progress bar and the length. `uri` already carries the sign-in token. */
export default function VoicePlayer({ uri, out }) {
  const player = useAudioPlayer({ uri })
  const st = useAudioPlayerStatus(player)
  const [failed, setFailed] = useState(false)
  useEffect(() => { setAudioModeAsync({ playsInSilentMode: true }).catch(() => {}) }, [])
  useEffect(() => { if (st.didJustFinish) { try { player.seekTo(0) } catch { /* ignore */ } } }, [st.didJustFinish]) // eslint-disable-line react-hooks/exhaustive-deps

  const dur = Number(st.duration) || 0, cur = Number(st.currentTime) || 0
  const pct = dur > 0 ? Math.min(100, (cur / dur) * 100) : 0
  const fg = out ? '#fff' : c.primary
  function toggle() {
    try { if (st.playing) player.pause(); else player.play() } catch { setFailed(true) }
  }
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10, minWidth: 190, paddingVertical: 4 }}>
      <TouchableOpacity onPress={toggle} activeOpacity={0.8} accessibilityLabel={st.playing ? 'Pause' : 'Play'} style={{ width: 40, height: 40, borderRadius: 20, backgroundColor: out ? 'rgba(255,255,255,0.22)' : c.primarySoft, alignItems: 'center', justifyContent: 'center' }}>
        <Icon name={st.playing ? 'pause' : 'play'} size={20} color={fg} />
      </TouchableOpacity>
      <View style={{ flex: 1 }}>
        <View style={{ height: 4, borderRadius: 2, backgroundColor: out ? 'rgba(255,255,255,0.3)' : c.border, overflow: 'hidden' }}><View style={{ width: `${pct}%`, height: 4, backgroundColor: fg }} /></View>
        <Text style={{ marginTop: 4, fontSize: 11.5, color: out ? '#cfe2f8' : c.faint }}>{failed ? 'Cannot play this note' : `${mmss((st.playing || cur > 0 ? cur : dur) * 1000)}${dur ? ' / ' + mmss(dur * 1000) : ''}`}</Text>
      </View>
    </View>)
}
