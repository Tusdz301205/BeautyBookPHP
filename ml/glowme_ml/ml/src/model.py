"""One shared PhoBERT-base encoder, four independent four-class output blocks."""
from __future__ import annotations
import torch
from torch import nn
from transformers import AutoModel
from .data import MODEL_CACHE


class AspectHeads(nn.Module):
    def __init__(self, hidden, n_aspects=4, dropout=0.1):
        super().__init__()
        self.dropout = nn.Dropout(dropout)
        self.proj = nn.Linear(hidden, n_aspects * 4)

    def forward(self, h):
        return self.proj(self.dropout(h)).view(h.size(0), 4, 4)


class MultiAspectPhoBERT(nn.Module):
    def __init__(self, dropout=0.1, encoder=None):
        super().__init__()
        self.encoder = encoder if encoder is not None else AutoModel.from_pretrained(
            str(MODEL_CACHE), local_files_only=True, add_pooling_layer=False)
        self.heads = nn.ModuleDict({"beauty": AspectHeads(self.encoder.config.hidden_size, dropout=dropout)})

    def forward(self, input_ids, attention_mask):
        output = self.encoder(input_ids=input_ids, attention_mask=attention_mask)
        return self.heads["beauty"](output.last_hidden_state[:, 0])


def aspect_loss(logits, labels):
    return nn.functional.cross_entropy(logits.reshape(-1, 4).float(), labels.reshape(-1))


def optimizer_groups(model, weight_decay):
    no_decay = ("bias", "LayerNorm.weight", "LayerNorm.bias")
    return [{"params": [p for n, p in model.named_parameters() if not any(x in n for x in no_decay)],
             "weight_decay": weight_decay},
            {"params": [p for n, p in model.named_parameters() if any(x in n for x in no_decay)],
             "weight_decay": 0.0}]
