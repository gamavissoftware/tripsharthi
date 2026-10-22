import { useEffect, useRef, useState } from 'react'
import { View, Text, TouchableOpacity, Alert } from 'react-native'
import { useAudioRecorder, useAudioRecorderState, RecordingPresets, AudioModule, setAudioModeAsync } from 'expo-audio'
import { Icon } from './ui'
import { c } from './theme'

const MAX_SECONDS = 300
export const mmss = (ms) => { const s = Math.floor((ms || 0) / 1000); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` }

/**
 * Replaces the message box while a voice note is being recorded. Mounting it starts the microphone; the bin throws the recording away,
 * the send button stops it and hands the file to the chat ({ uri, name, mimeType, size }). Recording is m4a (AAC), which WhatsApp accepts as audio.
 */
export default function VoiceBar({ onDone, onCancel, sending }) {
  const rec = useAudioRecorder(RecordingPresets.HIGH_QUALITY)
  const state = useAudioRecorderState(rec, 250)
  const [ready, setReady] = useState(false)
  const done = useRef(false)

  useEffect(() => {
    let dead = false
    ;(async () => {
      try {
        const p = await AudioModule.requestRecordingPermissionsAsync()
        if (!p.granted) { Alert.alert('Microphone is off', 'Allow the microphone for TripSarthi in your phone settings to record a voice note.'); onCancel(); return }
        await setAudioModeAsync({ allowsRecording: true, playsInSilentMode: true })
        await rec.prepareToRecordAsync(); if (dead) return
        rec.record(); setReady(true)
      } catch (e) { Alert.alert('Could not record', e.message || 'Try again.'); onCancel() }
    })()
    return () => { dead = true; try { if (!done.current) rec.stop() } catch { /* already stopped */ }; setAudioModeAsync({ allowsRecording: false }).catch(() => {}) }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  async function finish() {
    if (done.current) return
    done.current = true
    try {
      await rec.stop()
      const uri = rec.uri
      if (!uri) throw new Error('Nothing was recorded.')
      if ((state.durationMillis || 0) < 800) { Alert.alert('Too short', 'Hold on a little longer to record your message.'); onCancel(); return }
      onDone({ uri, name: `voice-${Date.now()}.m4a`, mimeType: 'audio/mp4', type: 'audio' })
    } catch (e) { done.current = false; Alert.alert('Could not save the recording', e.message || 'Try again.'); onCancel() }
  }
  useEffect(() => { if (ready && (state.durationMillis || 0) >= MAX_SECONDS * 1000) finish() }, [state.durationMillis, ready]) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12, padding: 10, backgroundColor: c.card }}>
      <TouchableOpacity onPress={() => { done.current = true; try { rec.stop() } catch { /* ignore */ } onCancel() }} hitSlop={10} style={{ width: 42, height: 46, alignItems: 'center', justifyContent: 'center' }} accessibilityLabel="Throw away the recording">
        <Icon name="trash" size={24} color={c.danger} />
      </TouchableOpacity>
      <View style={{ flex: 1, flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: c.dangerSoft, borderRadius: 22, paddingHorizontal: 16, height: 46 }}>
        <View style={{ width: 10, height: 10, borderRadius: 5, backgroundColor: c.danger, opacity: state.isRecording ? 1 : 0.4 }} />
        <Text style={{ color: c.text, fontWeight: '700', fontSize: 16, fontVariant: ['tabular-nums'] }}>{mmss(state.durationMillis)}</Text>
        <Text style={{ color: c.sub, fontSize: 12.5, flex: 1 }} numberOfLines={1}>{ready ? 'Recording…' : 'Starting…'}</Text>
      </View>
      <TouchableOpacity disabled={!ready || sending} onPress={finish} style={{ backgroundColor: c.primary, width: 46, height: 46, borderRadius: 23, alignItems: 'center', justifyContent: 'center', opacity: ready && !sending ? 1 : 0.45 }} accessibilityLabel="Send the voice note">
        <Icon name="send" size={19} color="#fff" />
      </TouchableOpacity>
    </View>)
}
